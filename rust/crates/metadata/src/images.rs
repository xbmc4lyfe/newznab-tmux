//! Fetches and stores cover images. Port of `ReleaseImageService::saveRemoteImage`.

use std::fs;
use std::io::Cursor;
use std::net::IpAddr;
use std::os::unix::fs::PermissionsExt;
use std::path::{Path, PathBuf};
use std::sync::LazyLock;
use std::time::Duration;

use anyhow::{Context, Result, anyhow, bail};
use image::imageops::FilterType;
use image::{DynamicImage, ImageDecoder, ImageReader};
use nntmux_core::pcre::Pcre;
use nntmux_core::{optional, parsed_or, var_or};
use rand::Rng;
use reqwest::{Client, Url};
use tracing::debug;

static IMAGE_NAME: LazyLock<Pcre> =
    LazyLock::new(|| Pcre::new(r"/\A[A-Za-z0-9][A-Za-z0-9_-]*\z/D").expect("a valid regex"));

/// Image bounds used by the cover producers.
#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Profile {
    Original,
    MetadataCover,
    Backdrop,
}

impl Profile {
    fn bounds(self) -> Option<(u32, u32)> {
        match self {
            Self::Original => None,
            Self::MetadataCover => Some((250, 250)),
            Self::Backdrop => Some((1920, 1024)),
        }
    }
}

/// From `config/image.php`, and where the covers live.
#[derive(Clone, Debug)]
pub struct Images {
    client: Client,
    pub covers: PathBuf,
    webp: bool,
    quality: u8,
    max_source_bytes: u64,
    max_source_pixels: u64,
    max_redirects: u32,
    /// Lets tests fetch from a local mock server. Always false in production.
    pub allow_private_hosts: bool,
}

impl Images {
    pub fn from_env() -> Result<Self> {
        let format = var_or("IMAGE_OUTPUT_FORMAT", "webp").to_ascii_lowercase();
        let storage = PathBuf::from(var_or("NNTMUX_STORAGE_DIR", "/app/storage"));
        let client = Client::builder()
            .redirect(reqwest::redirect::Policy::none())
            .connect_timeout(Duration::from_secs(
                parsed_or::<u64>("IMAGE_FETCH_CONNECT_TIMEOUT", 5)?.max(1),
            ))
            .timeout(Duration::from_secs(
                parsed_or::<u64>("IMAGE_FETCH_TIMEOUT", 30)?.max(1),
            ))
            .user_agent("NNTmux Image Fetcher")
            .build()?;
        Ok(Self {
            client,
            covers: optional("COVERS_PATH").map_or_else(|| storage.join("covers"), PathBuf::from),
            webp: !matches!(format.as_str(), "jpg" | "jpeg"),
            quality: u8::try_from(parsed_or::<i64>("IMAGE_OUTPUT_QUALITY", 82)?.clamp(1, 100))?,
            max_source_bytes: u64::try_from(
                parsed_or::<i64>("IMAGE_MAX_SOURCE_BYTES", 20 * 1024 * 1024)?.max(1),
            )?,
            max_source_pixels: u64::try_from(
                parsed_or::<i64>("IMAGE_MAX_SOURCE_PIXELS", 40_000_000)?.max(1),
            )?,
            max_redirects: u32::try_from(parsed_or::<i64>("IMAGE_FETCH_MAX_REDIRECTS", 5)?.max(0))?,
            allow_private_hosts: false,
        })
    }

    pub fn extension(&self) -> &'static str {
        if self.webp { "webp" } else { "jpg" }
    }

    /// `saveRemoteImage`. False when the image can't be fetched or used, which only means no cover.
    pub async fn save_remote(&self, name: &str, url: &str, kind: &str, profile: Profile) -> bool {
        let directory = self.covers.join(kind);
        match self.try_save_remote(name, url, &directory, profile).await {
            Ok(_) => true,
            Err(failure) => {
                debug!(
                    url = redacted(url),
                    error = format!("{failure:#}"),
                    "cover not saved"
                );
                false
            }
        }
    }

    async fn try_save_remote(
        &self,
        name: &str,
        url: &str,
        directory: &Path,
        profile: Profile,
    ) -> Result<PathBuf> {
        let contents = self.fetch(url).await?;
        let this = self.clone();
        let name = name.to_owned();
        let directory = directory.to_owned();
        tokio::task::spawn_blocking(move || this.process(&name, &contents, &directory, profile))
            .await
            .context("the image task failed")?
    }

    async fn fetch(&self, url: &str) -> Result<Vec<u8>> {
        let mut current = Url::parse(url).context("Remote image URL is invalid.")?;
        for redirects in 0..=self.max_redirects {
            self.assert_safe(&current).await?;
            let mut response = self
                .client
                .get(current.clone())
                .header(
                    reqwest::header::ACCEPT,
                    "image/avif,image/webp,image/png,image/jpeg,*/*;q=0.5",
                )
                .send()
                .await?;
            let status = response.status();
            if status.is_redirection() {
                let location = response
                    .headers()
                    .get(reqwest::header::LOCATION)
                    .and_then(|value| value.to_str().ok())
                    .filter(|value| !value.is_empty());
                let Some(location) = location else {
                    bail!("Remote image redirect limit was exceeded.");
                };
                if redirects == self.max_redirects {
                    bail!("Remote image redirect limit was exceeded.");
                }
                current = current
                    .join(location)
                    .context("Remote image URL is invalid.")?;
                continue;
            }
            if !status.is_success() {
                bail!("Remote image request failed: {status}");
            }
            if response
                .content_length()
                .is_some_and(|length| length > self.max_source_bytes)
            {
                bail!("Remote image exceeds the configured size limit.");
            }
            let mut contents = Vec::new();
            while let Some(chunk) = response.chunk().await? {
                contents.extend_from_slice(&chunk);
                if contents.len() as u64 > self.max_source_bytes {
                    bail!("Remote image exceeds the configured size limit.");
                }
            }
            if contents.is_empty() {
                bail!("Remote image response was empty.");
            }
            return Ok(contents);
        }
        bail!("Unable to fetch the remote image.")
    }

    /// Refuses URLs that reach this host or its network.
    async fn assert_safe(&self, url: &Url) -> Result<()> {
        if !matches!(url.scheme(), "http" | "https")
            || !url.username().is_empty()
            || url.password().is_some()
        {
            bail!("Remote image URL is invalid.");
        }
        let host = url.host_str().context("Remote image URL is invalid.")?;
        if self.allow_private_hosts {
            return Ok(());
        }
        let host = host.trim_start_matches('[').trim_end_matches(']');
        let addresses: Vec<IpAddr> = if let Ok(address) = host.parse() {
            vec![address]
        } else {
            tokio::net::lookup_host((host, url.port_or_known_default().unwrap_or(443)))
                .await
                .context("Remote image host did not resolve.")?
                .map(|address| address.ip())
                .collect()
        };
        if addresses.is_empty() {
            bail!("Remote image host did not resolve.");
        }
        if addresses.iter().any(|address| !is_public(*address)) {
            bail!("Remote image host resolves to a non-public address.");
        }
        Ok(())
    }

    fn process(
        &self,
        name: &str,
        contents: &[u8],
        directory: &Path,
        profile: Profile,
    ) -> Result<PathBuf> {
        if !IMAGE_NAME.is_match(name.as_bytes()) {
            bail!("Image basename is invalid.");
        }
        if contents.is_empty() || contents.len() as u64 > self.max_source_bytes {
            bail!("Image source size is invalid or exceeds the configured limit.");
        }
        let image = decode_oriented(contents, self.max_source_pixels)?;
        let image = match profile.bounds() {
            Some((width, height)) => shrink(image, width, height),
            None => image,
        };
        let encoded = self.encode(&image)?;

        fs::create_dir_all(directory).context("creating the image directory")?;
        let extension = self.extension();
        let destination = directory.join(format!("{name}.{extension}"));
        let suffix = hex::encode(rand::rng().random::<[u8; 8]>());
        let temporary = directory.join(format!(".{name}.{suffix}.tmp.{extension}"));
        let published = publish(&encoded, &temporary, &destination);
        if published.is_err() {
            let _ = fs::remove_file(&temporary);
        }
        published?;
        Ok(destination)
    }

    fn encode(&self, image: &DynamicImage) -> Result<Vec<u8>> {
        let rgb = image.to_rgb8();
        if self.webp {
            let encoder = webp::Encoder::from_rgb(rgb.as_raw(), rgb.width(), rgb.height());
            return Ok(encoder.encode(f32::from(self.quality)).to_vec());
        }
        let mut out = Vec::new();
        image::codecs::jpeg::JpegEncoder::new_with_quality(&mut out, self.quality)
            .encode_image(&rgb)
            .map_err(|failure| anyhow!("Unable to encode the image: {failure}"))?;
        Ok(out)
    }
}

fn publish(encoded: &[u8], temporary: &Path, destination: &Path) -> Result<()> {
    fs::write(temporary, encoded).context("Unable to write the processed image.")?;
    fs::rename(temporary, destination)
        .context("Unable to atomically publish the processed image.")?;
    fs::set_permissions(destination, fs::Permissions::from_mode(0o644))?;
    Ok(())
}

fn decode_oriented(bytes: &[u8], max_pixels: u64) -> Result<DynamicImage> {
    let mut reader = ImageReader::new(Cursor::new(bytes)).with_guessed_format()?;
    reader.no_limits();
    let mut decoder = reader
        .into_decoder()
        .context("Unable to decode the image.")?;
    let (width, height) = decoder.dimensions();
    if width == 0 || height == 0 || u64::from(width) * u64::from(height) > max_pixels {
        bail!("Decoded image dimensions exceed the configured limit.");
    }
    let orientation = decoder.orientation()?;
    let mut image = DynamicImage::from_decoder(decoder).context("Unable to decode the image.")?;
    image.apply_orientation(orientation);
    Ok(image)
}

fn shrink(image: DynamicImage, max_width: u32, max_height: u32) -> DynamicImage {
    let (width, height) = (image.width(), image.height());
    let ratio = (f64::from(max_height) / f64::from(height))
        .min(f64::from(max_width) / f64::from(width))
        .min(1.0);
    let new_width = (ratio * f64::from(width)).floor() as u32;
    let new_height = (ratio * f64::from(height)).floor() as u32;
    if ratio < 1.0 && new_width > 10 && new_height > 10 {
        return image.resize_exact(new_width, new_height, FilterType::CatmullRom);
    }
    image
}

/// `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`.
fn is_public(address: IpAddr) -> bool {
    match address {
        IpAddr::V4(v4) => {
            !(v4.is_private()
                || v4.is_loopback()
                || v4.is_link_local()
                || v4.is_unspecified()
                || v4.is_broadcast()
                || v4.octets()[0] == 0
                || v4.octets()[0] >= 240)
        }
        IpAddr::V6(v6) => {
            if let Some(mapped) = v6.to_ipv4_mapped() {
                return is_public(IpAddr::V4(mapped));
            }
            let first = v6.segments()[0];
            !(v6.is_loopback()
                || v6.is_unspecified()
                || (first & 0xfe00) == 0xfc00
                || (first & 0xffc0) == 0xfe80)
        }
    }
}

fn redacted(url: &str) -> String {
    Url::parse(url).map_or_else(
        |_| "[invalid-url]".to_owned(),
        |url| {
            format!(
                "{}://{}{}",
                url.scheme(),
                url.host_str().unwrap_or_default(),
                url.path()
            )
        },
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn address_filter() {
        for private in [
            "10.0.0.1",
            "192.168.1.1",
            "127.0.0.1",
            "169.254.169.254",
            "::1",
            "fd00::1",
            "fe80::1",
            "::ffff:10.0.0.1",
            "0.1.2.3",
            "250.0.0.1",
        ] {
            assert!(!is_public(private.parse().unwrap()), "{private}");
        }
        for public in ["8.8.8.8", "2606:4700:4700::1111"] {
            assert!(is_public(public.parse().unwrap()), "{public}");
        }
    }

    #[test]
    fn redaction_drops_the_query() {
        assert_eq!(
            redacted("https://a.test/x.jpg?api_key=1"),
            "https://a.test/x.jpg"
        );
    }

    #[test]
    fn shrink_keeps_the_ratio_and_never_grows() {
        let large = DynamicImage::new_rgb8(1000, 500);
        let small = shrink(large, 250, 250);
        assert_eq!((small.width(), small.height()), (250, 125));
        let tiny = shrink(DynamicImage::new_rgb8(100, 50), 250, 250);
        assert_eq!((tiny.width(), tiny.height()), (100, 50));
    }
}
