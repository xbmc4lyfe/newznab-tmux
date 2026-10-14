# Cloud and bare-metal deployment

These runbooks cover the existing managed cloud and physical-server tooling. For the local Docker Compose stack, see the [README](../README.md#run-the-local-docker-compose-stack).

## Cloud deployment with Terraform


Select **AWS**, **Hetzner Cloud**, or **OVHcloud Public Cloud** using `deploy/select.sh PROVIDER`. Each provider has an independent Terraform root, dependency lock file, backend key, and infrastructure tests. Application containers, configuration validation, initialization, health checks, and release orchestration live in `deploy/cloud/`; the AWS script paths remain compatibility entrypoints. Provider selection provisions separate installations; it does not migrate an existing installation between clouds.

| Provider | Administration | Configuration / images | Daily backup | Default compute |
|---|---|---|---|---|
| `aws` | Systems Manager; no public SSH | Secrets Manager / Terraform-managed ECR | Encrypted EBS snapshots, seven recovery points | `m7i.2xlarge` |
| `hetzner` | SSH from required trusted administrator CIDRs | Existing Vault KV v2 / existing OCI registry | Encrypted Restic backups, seven recovery points | `ccx33` |
| `ovh` | SSH from required trusted administrator CIDRs | Existing Vault KV v2 / existing OCI registry | Encrypted Restic backups, seven recovery points | `b3-32` |

All defaults target x86_64, 8 vCPUs and 32 GiB RAM, with a separate 250 GiB data volume. Hetzner and OVH use guest LUKS2 encryption for the data disk and store deployment configuration/state on that encrypted disk. **Encryption of their stock Ubuntu boot disks is not provided by this deployment.** Docker metadata and writable layers reside on the encrypted data volume using the classic image store; the Vault bootstrap token remains a restricted file on the boot disk. See [Docker data directory configuration](https://docs.docker.com/engine/daemon/#daemon-data-directory). Boot disk size on Hetzner follows the server type. AWS root/data encryption remains provider-managed. Volumes are protected from Terraform destruction; no boot command formats a disk.

Common operations are:

```bash
deploy/select.sh aws terraform init -backend-config=backend.hcl
deploy/select.sh aws terraform plan -out=deployment.tfplan
# Review the selected provider's plan before applying it.
deploy/select.sh aws terraform apply deployment.tfplan
deploy/select.sh aws volume-initialize
deploy/select.sh aws initialize sha256:REPLACE_WITH_64_HEX_DIGITS
deploy/select.sh aws release sha256:REPLACE_WITH_64_HEX_DIGITS
deploy/select.sh aws backup
# Replace aws with hetzner or ovh after following their bootstrap instructions below.
```

### Hetzner and OVH setup

Use a fresh Hetzner project (`HCLOUD_TOKEN`) or an existing OVH Public Cloud project with its OpenStack environment (`OS_AUTH_URL`, project/tenant, user/password or application credentials). Set the required provider region, administrator public SSH key, and narrow IPv4 administrator CIDRs in the selected `terraform.tfvars`. OVH uses OpenStack for compute/network/storage and the OVH provider for optional native DNS; supply `OVH_ENDPOINT`, `OVH_APPLICATION_KEY`, `OVH_APPLICATION_SECRET`, and `OVH_CONSUMER_KEY` when managing an OVH DNS zone. Terraform never receives your private SSH key, Vault token, application configuration, or backup credentials.

The OVH region must support Gateway/Floating IP services, the chosen image/flavor, volume type, and availability zone. The instance has only a private network interface, with a Gateway and retained Floating IP for access. Hetzner uses a retained Primary IPv4 and a dedicated private network. Both firewalls expose HTTP/HTTPS publicly and allow SSH only from your supplied `/24` or narrower CIDRs. MariaDB, Redis, Manticore, and Caddy administration remain private. Use `dns_zone` for an existing Hetzner primary zone or OVH DNS zone; leave it unset when DNS is managed elsewhere, and point the application hostname at `static_ip` yourself before initialization.

```bash
provider=hetzner # or ovh
cp "infra/terraform/$provider/backend.hcl.example" "infra/terraform/$provider/backend.hcl"
cp "infra/terraform/$provider/terraform.tfvars.example" "infra/terraform/$provider/terraform.tfvars"
# Edit the files; use a unique state key for each provider and environment.
deploy/select.sh "$provider" terraform init -backend-config=backend.hcl
deploy/select.sh "$provider" terraform plan -out=deployment.tfplan
deploy/select.sh "$provider" terraform apply deployment.tfplan
```

The backend examples use the existing encrypted/versioned S3 state bucket described below; that state bucket can serve all three roots under separate keys. A pre-existing S3-compatible backend can instead be configured with its HTTPS `endpoints.s3`, `use_path_style=true`, and `skip_credentials_validation`, `skip_region_validation`, `skip_requesting_account_id`, and `skip_metadata_api_check` where required. Confirm support for conditional lockfile writes and versioning before using a compatible service. Backend authentication is separate from instance credentials and comes from the Terraform credential environment; never commit it.

Provide an existing HTTPS Vault KV v2 secret. The secret contains the **same `application_env` dotenv configuration shown in the AWS section below**, plus these fields:

| Vault field | Value |
|---|---|
| `application_env` | Complete production dotenv string, including a stable APP_KEY, administrator and NNTP credentials |
| `volume_key` | Base64 encoding of at least 32 cryptographically random bytes; keep this key stable and recoverable |
| `restic_repository` | Dedicated prefix such as `s3:https://OBJECT_STORAGE_ENDPOINT/BUCKET/nntmux/hetzner/production` |
| `restic_password` | Strong, stable Restic repository password |
| `aws_access_key_id`, `aws_secret_access_key` | S3-compatible backup credentials restricted to that repository prefix |
| `aws_default_region` | Backup service's region, when required |
| `registry_username`, `registry_password` | Optional pair for a private OCI registry; omit both for public images |

Generate the volume key with `openssl rand -base64 32`. Configure `vault_secret_path` as the KV v2 API path, for example `secret/data/nntmux/hetzner/production`. Give the host's renewable Vault token only read access to that secret. Arrange token renewal or replacement before expiry; unattended reboots need a valid token and Vault connectivity to unlock storage. Store recovery copies of the volume key, Restic password, backup access, and Vault access outside this server. Release preflight verifies that the current Vault volume key still unlocks the active disk.

Build and publish manually to your existing registry (`docker build --platform linux/amd64`, `docker push`), then deploy its immutable digest. The application is the same production image on every provider. Composer/npm dependency layers are cached separately from application source and runtime assets.

Verify the SSH host fingerprint through the provider console and install it in your local known-hosts file; the selector requires existing trusted host keys and uses your SSH agent/default identity. After cloud-init completes, bootstrap the shared assets and upload the restricted Vault token from a local file:

```bash
deploy/select.sh "$provider" bootstrap
# TARGET is root@STATIC_IP for Hetzner, ubuntu@STATIC_IP for OVH.
ssh TARGET 'sudo -n install -d -m 0755 /etc/nntmux; sudo -n sh -c "umask 077; cat > /etc/nntmux/vault-token"' < /absolute/path/to/vault-token
# Remove the temporary local token file after confirming delivery.
deploy/select.sh "$provider" volume-initialize
deploy/select.sh "$provider" initialize sha256:REPLACE_WITH_64_HEX_DIGITS
deploy/select.sh "$provider" start
```

Bootstrap installs service assets, the Docker mount dependency, and a Docker data directory at `/srv/nntmux/docker`, preserving an existing `/etc/nntmux/host.json`. Refresh existing assets only during maintenance, and update host identity parameters explicitly when Terraform inputs change; ignored user-data/image changes never silently replace a running indexer. Initialization enables the daily backup timer. Configuration is fetched from Vault without placing its token in process arguments or logs, and mounted read-only into containers. APP_KEY and database credentials retain the same release guards as AWS. Enable indexing groups and adjust processing settings after initialization.

### Hetzner and OVH recovery

Daily backups stop all containers and the Docker daemon, flush writes, and back up the data disk with Restic encryption. Docker images and writable layers are excluded from Restic recovery points; activation pulls the recorded immutable image and recreates containers. The repository is initialized only if needed; an existing repository is never overwritten. Failed backup commands restart the previously running services and do not prune recovery points. Successful daily backups keep the latest seven successful daily snapshots for the provider/environment; release/recovery snapshots remain until explicitly removed. Remote garbage collection runs **after services restart**, under the shared operation lock. Logs are in `journalctl -u nntmux-backup.service`; use a securely authenticated Restic client to list full snapshot IDs.

For restore, provision an additional protected volume using `create_recovery_volume=true`, review/apply the plan, and record `recovery_volume_id`. Initialize that separate blank volume explicitly, then restore:

```bash
deploy/select.sh "$provider" terraform plan -var=create_recovery_volume=true -out=recovery.tfplan
deploy/select.sh "$provider" terraform apply recovery.tfplan
# Save create_recovery_volume=true in terraform.tfvars before future plans.
deploy/select.sh "$provider" terraform output recovery_volume_id
deploy/select.sh "$provider" volume-initialize RECOVERY_VOLUME_ID
deploy/select.sh "$provider" restore FULL_RESTIC_SNAPSHOT_ID RECOVERY_VOLUME_ID
```

Staging requires an initialized, empty, separate volume. Restore verifies backup ownership, contents, installation metadata, and application/database identity while preserving the active deployment. A staged restore remains mounted for inspection; its output provides the staging host configuration and mount path for manual cleanup. To activate the inspected restore, rerun the **same snapshot and volume IDs** with `--activate`; the recorded recovery manifest must match, and existing restored files are not overwritten. You can also pass `--activate` on the initial command to restore and activate in one operation:

```bash
deploy/select.sh "$provider" restore FULL_RESTIC_SNAPSHOT_ID RECOVERY_VOLUME_ID --activate
```

Activation backs up the current installation, stops services, mounts the recovered volume as the application disk, loads its matching image/configuration, and verifies services in maintenance before reopening the application. The original disk remains attached and retained. A failed activation stops application writers for explicit recovery. After successful activation, set both `create_recovery_volume=true` and `use_recovery_volume=true` in `terraform.tfvars`; review the next plan to confirm no storage/instance replacement. This reconciles the selected host configuration while retaining both protected volumes. Further recoveries require another empty target and a deliberate retention/state decision; do not erase an existing recovery disk automatically.

### AWS configuration and operations

The first AWS deployment supports a **fresh database** on a single Ubuntu 24.04 EC2 instance with Docker Compose, Caddy HTTPS, MariaDB, Redis, Manticore, Horizon, scheduler, and tmux processing. Defaults are `m7i.2xlarge` (8 vCPUs, 32 GiB RAM), a 40 GiB root disk, and a separate 250 GiB encrypted gp3 data disk. HTTP/HTTPS are public; administration uses Systems Manager. Brief downtime is expected during upgrades and daily consistent backups. This is a single-instance deployment, without automatic failover.

### Infrastructure and state

Use Terraform 1.10+ and an AWS CLI profile or assumed role. Supply an existing public Route 53 zone, a hostname inside it, a region, and an availability zone. The AWS provider is constrained to major version 6 and the checked-in lock file selects its exact version.

The S3 backend bucket must exist **before initialization**. Configure bucket versioning, default server-side encryption, public-access blocking, and a bucket policy requiring TLS. Grant the Terraform operator `s3:ListBucket` scoped to its state prefix, `s3:GetObject`/`s3:PutObject` for its state object, and `s3:GetObject`/`s3:PutObject`/`s3:DeleteObject` for the corresponding `.tflock` object; grant KMS permissions too if the bucket uses a customer-managed key. Use separate state keys for each environment. Backend credentials come from the AWS credential chain and must not be written into backend files. See [S3 backend configuration](https://developer.hashicorp.com/terraform/language/backend/s3).

```bash
cd infra/terraform/aws
cp backend.hcl.example backend.hcl
cp terraform.tfvars.example terraform.tfvars
# Edit both files with your existing bucket, region, zone, and hostname.
terraform init -backend-config=backend.hcl
terraform plan -out=deployment.tfplan
terraform apply deployment.tfplan
terraform output
```

Outputs identify the instance, Elastic IP, hostname, data volume, ECR repository, application secret ARN, and versioned deployment asset location. No application secrets are in Terraform state or user data. The instance role can read its secret and repository, fetch deployment assets, and manage snapshots/recovery volumes tagged for this deployment; it cannot publish images or update secrets.

The data volume has `prevent_destroy`. Destroying the whole root therefore requires a deliberate data-retention decision. Instance AMI and user-data changes are ignored to avoid replacing a running indexer when Ubuntu or deployment assets change. Refresh host assets explicitly during maintenance: use `terraform output -raw assets_location`, copy that S3 prefix into a temporary directory on the instance, stop `nntmux.service`, then replace `/opt/nntmux` assets, copy the service/timer files into `/etc/systemd/system`, copy `docker-data.conf` into `/etc/systemd/system/docker.service.d/nntmux-data.conf`, and run `systemctl daemon-reload`. Do not overwrite `/etc/nntmux/host.json` or application configuration. AMI upgrades require an explicit replacement/restore procedure. Host identity changes such as hostname/secret ARN require an explicit update of `/etc/nntmux/host.json` during maintenance, followed by a release with matching secret configuration. After increasing `data_volume_size`, expand the mounted ext4 filesystem explicitly with `resize2fs` on its verified EBS device; Terraform expands the disk, not the filesystem.

### Publish an image and configure secrets

Build on an x86_64 host, or use Buildx with `--platform linux/amd64`. This image bundles production Composer dependencies and Vite assets; it does not bundle `.env`, database files, installation locks, or host storage. The build requires access to public Composer/npm registries and the forum repository.

```bash
repository=$(terraform -chdir=infra/terraform/aws output -raw repository_url)
aws ecr get-login-password --region eu-central-1 | docker login --username AWS --password-stdin "${repository%%/*}"
docker build --platform linux/amd64 -t "$repository:release-001" .
docker push "$repository:release-001"
aws ecr describe-images --region eu-central-1 --repository-name "${repository#*/}" --image-ids imageTag=release-001 --query 'imageDetails[0].imageDigest' --output text
```

Populate the Terraform-created Secrets Manager secret **outside Terraform** with a UTF-8 dotenv string. Start from `.env.example`, add your metadata keys, and use these deployment-specific values. Single-quote passwords containing `$` or `#` according to dotenv syntax. Database identity/password changes and APP_KEY rotation are intentionally refused by normal release commands; plan those operations separately.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://indexer.example.com
APP_KEY=base64:REPLACE_WITH_A_GENERATED_32_BYTE_KEY
DB_CONNECTION=mariadb
DB_HOST=mariadb
DB_PORT=3306
DB_DATABASE=nntmux
DB_USERNAME=nntmux
DB_PASSWORD='REPLACE_WITH_APPLICATION_DB_PASSWORD'
DB_ROOTPASSWORD='REPLACE_WITH_SEPARATE_ROOT_PASSWORD'
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=null
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
SEARCH_DRIVER=manticore
MANTICORESEARCH_HOST=manticore
MANTICORESEARCH_PORT=9308
MANTICORESEARCH_HOSTS=
TRUSTED_PROXIES=172.30.42.0/24
COVERS_PATH=/app/storage/covers
PATH_TO_NZBS=/app/storage/nzb
TEMP_UNRAR_PATH=/app/storage/tmp/unrar
TEMP_UNZIP_PATH=/app/storage/tmp/unzip
ADMIN_USER=admin
ADMIN_PASS='REPLACE_WITH_AT_LEAST_12_CHARACTERS'
ADMIN_EMAIL=admin@example.com
NNTP_SERVER=news.example.com
NNTP_PORT=563
NNTP_SSLENABLED=true
NNTP_USERNAME='REPLACE_WITH_NNTP_USERNAME'
NNTP_PASSWORD='REPLACE_WITH_NNTP_PASSWORD'
USE_ALTERNATE_NNTP_SERVER=false
```

Generate APP_KEY locally with `php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'`. Upload a restricted temporary configuration file with `aws secretsmanager put-secret-value --secret-id SECRET_ARN --secret-string file:///absolute/path/to/configuration.env --region REGION`, then remove that temporary file. Do not commit it. Deployment fetches the secret into a host file readable only by the application UID, with its parent directory restricted to root, and mounts that file read-only. MariaDB receives a separate root-only configuration file parsed using the same dotenv library as Laravel. Recovery snapshots include restricted copies of this configuration, so snapshot access also grants access to secrets.

### Initialize and release through Systems Manager

After EC2 cloud-init finishes, run commands through a Systems Manager session or `AWS-RunShellScript`. The instance must have outbound access to AWS APIs, image registries, NNTP, and enabled metadata services. DNS must resolve to the Elastic IP before Caddy can obtain its certificate.

```bash
aws ssm send-command --region REGION --instance-ids INSTANCE_ID \
  --document-name AWS-RunShellScript \
  --parameters '{"commands":["/opt/nntmux/volume.sh initialize","systemctl start nntmux-data.service","systemctl restart docker.service","/opt/nntmux/deploy.sh initialize sha256:REPLACE_WITH_64_HEX_DIGITS","systemctl start nntmux.service"]}'
```

`volume.sh initialize` formats only a disk without detected filesystem signatures, partitions, or mounts. It is a one-time command; never include it in routine deployment or reboot scripts. Boot only mounts the configured ext4 volume, verifies its EBS identity, and requires that mount before starting containers. A Docker systemd drop-in also requires the mount, preventing Docker restart policies from starting containers against an unmounted path after power loss. The shared operation lock prevents overlapping initialization, releases, backups, and restores.

`nntmux:deploy-init` rejects any existing tables/views or success marker. It runs migrations and seeders without `migrate:fresh`, creates and verifies the administrator, creates Manticore tables without `--drop`, and preserves APP_KEY. An initialization failure can leave a partially created database; do not erase it automatically or rerun the installer. Inspect the cause and explicitly recover an empty database before retrying. Enable indexing groups and configure runtime processing settings in the admin UI after initialization; the deployment preserves seeded processing defaults.

For subsequent releases invoke `/opt/nntmux/deploy.sh release sha256:DIGEST`. Configuration and image validation happen before downtime. The command enters maintenance mode, stops indexing/queues/scheduler, snapshots consistent storage, migrates, records the new digest, rebuilds caches in new service containers, verifies dependencies and processes, and exits maintenance. Failed migrations retain the previous release record and leave maintenance enabled with writers stopped; failed readiness after a successful migration retains the new digest so reboot does not silently select an older image against the new schema. Inspect `journalctl -u nntmux.service -u nntmux-backup.service`, SSM command output, and `docker compose --env-file /etc/nntmux/current.env -f /opt/nntmux/compose.yaml logs`.

The previous image/configuration is recorded in `/etc/nntmux/previous.env`. To roll back **only when the current schema supports the previous image**, invoke `/opt/nntmux/deploy.sh rollback sha256:PREVIOUS_DIGEST --schema-compatible`. This also snapshots the current state and never runs down migrations. Otherwise recover the image and data together from a backup.

### Backup and recovery

`nntmux-backup.timer` runs daily at approximately 03:00 UTC after successful initialization. `/opt/nntmux/deploy.sh backup` also runs manually. It copies configuration and release metadata onto the data disk, stops all containers and the Docker daemon, flushes filesystem writes, creates an encrypted EBS snapshot, waits for completion, and restarts the previously running services even when snapshot creation or waiting fails. It retains seven completed snapshots tagged `Purpose=daily`; snapshots made for releases and recovery are retained until explicitly cleaned up. A locked operation causes a backup run to fail visibly rather than overlap a release. Monitor the backup journal and snapshot timestamps.

Invoke `/opt/nntmux/deploy.sh restore snap-SNAPSHOT_ID` to create a separate encrypted volume, mount it separately, and verify installation/recovery metadata and database/key identity. This leaves the active volume untouched. Inspect the staged volume and clean it up manually when finished. Invoke `restore snap-SNAPSHOT_ID --activate` to create and activate a verified recovery volume: snapshot the current deployment, stop services, unmount/detach the original disk, attach/mount the replacement, restore matching image/configuration, and verify before exiting maintenance. The original volume is retained. Activation failure leaves services stopped for explicit recovery; inspect attachment/mount state rather than rerunning initialization.

After activation, reconcile Terraform state **before another plan/apply**, since recovery intentionally changed the active disk outside Terraform. Back up the remote state securely, record both volume IDs, remove only the old volume/attachment bindings (which does not delete AWS disks), and import the replacement:

```bash
terraform state rm aws_volume_attachment.data aws_ebs_volume.data
terraform import aws_ebs_volume.data vol-RESTORED_VOLUME_ID
terraform import aws_volume_attachment.data /dev/sdf:vol-RESTORED_VOLUME_ID:i-INSTANCE_ID
terraform plan
```

Verify there is no planned disk replacement, and adjust `data_volume_size` if the restored volume size differs. The original volume is now retained outside this state; keep its ID until recovery is accepted. Ensure the Secrets Manager value still matches the recovered APP_KEY/database identity before the next release. A staging restore consumes an additional disk and should be cleaned up manually after review.

### Validation

Run `terraform fmt -check -recursive`, `terraform init -backend=false`, `terraform validate`, `tflint --init && tflint`, and `terraform test` from each provider root. Terraform tests use provider mocks and plan only; CI never provisions infrastructure. Run `python3 -m unittest discover -s deploy/cloud/tests -p 'test_*.py'`, and `vendor/bin/phpunit tests/Feature/DeployInitializeTest.php` for deployment failures and initialization guards. CI also builds the production image, checks PHP extensions and bundled assets, runs disposable database/search/web/Horizon/scheduler integration with `deploy/cloud/tests/smoke.sh IMAGE`, and reports image vulnerabilities; ECR scans manually published images on push.

Before production acceptance, exercise a disposable environment on each provider: initialization, repeated release, reboot, HTTPS, authenticated cover/logo delivery, Horizon and scheduler activity, tmux ingestion with configured NNTP credentials, daily backup, staged restore, activated restore, and a repeated Terraform plan with no changes. Local/mocked checks alone do not prove provider permissions, live volume discovery, Vault access, certificate issuance, or live Usenet processing. CI also runs guarded loopback LUKS storage integration and native encrypted backup/restore tests; it never applies infrastructure.


## Native bare metal installation

The native installer targets existing **physical Ubuntu 24.04 LTS amd64 servers**. It installs Nginx/PHP 8.5, MariaDB 11.4, Redis 7, Manticore 28.4.4 or Elasticsearch 8.19, Horizon, the Laravel scheduler, archive/media tools, HTTPS, and encrypted remote backups directly on the host. Each server contains the full stack. It does not order hardware, install Ubuntu, create virtual machines, partition disks, or migrate an existing unmanaged installation. Existing cloud/Docker deployments retain their own tooling and state.

Provide SSH key/agent access with verified host keys, sudo, at least 8 GiB RAM and 20 GiB free disk for installation (production indexing needs substantially more), a public DNS hostname pointing directly to the server, and an existing S3-compatible backup bucket. Inbound ports 80/443 must be reachable for Let's Encrypt. Supply trusted SSH administrator CIDRs that include your current control-node address. Configure storage/RAID and mount data disks before running the installer. Unsupported virtualization/container targets and unmanaged service conflicts are rejected.

### Control-node setup and configuration

Use a macOS or Linux control node with Python 3.12 and Terraform >=1.10,<2.0. Install the pinned tools in a virtual environment:

```bash
python3 -m venv .venv-ansible
.venv-ansible/bin/pip install -r infra/ansible/requirements.txt
source .venv-ansible/bin/activate
ansible-galaxy collection install -r infra/ansible/requirements.yml
```

Keep operator files outside the checkout. Copy `infra/ansible/inventory/hosts.yml.example`, `settings.yml.example`, and `vault.yml.example` into a protected operator directory. Fill in settings and credentials, then encrypt the **whole** Vault file with `ansible-vault encrypt /secure/indexer-vault.yml`. Supply a valid, persistent APP_KEY (`base64:` plus 32 random bytes encoded as base64); preserve it for every update and restore. Never commit real credentials or pass secret values in command arguments. Protect Vault password files with mode 0600; omitting that file makes the entrypoint prompt for the Vault password. Use a distinct Vault file per host. Add `--ask-become-pass` when the SSH account requires a sudo password; it is prompted interactively.

Choose an immutable 40-character commit that contains this native deployment support. Set `nntmux_search_driver` to `manticore` (default) or `elasticsearch`. Installation settings and optional SMTP/API credentials are illustrated in the example files. Backup credentials are reserved for restic and are excluded from the application's environment. Native database/search listeners bind to loopback; Elasticsearch HTTP uses authenticated loopback access. Database and search packages are held against unattended upgrades. Keep the recorded package versions available in your package mirror for disaster recovery.

Terraform is optional for hand-written inventory. To use its separate, secret-free inventory root:

```bash
cp infra/terraform/baremetal/backend.hcl.example /secure/baremetal-backend.hcl
cp infra/terraform/baremetal/terraform.tfvars.example /secure/baremetal.tfvars
terraform -chdir=infra/terraform/baremetal init -backend-config=/secure/baremetal-backend.hcl
terraform -chdir=infra/terraform/baremetal plan -var-file=/secure/baremetal.tfvars -out=/secure/baremetal.tfplan
terraform -chdir=infra/terraform/baremetal apply /secure/baremetal.tfplan
```

Use an encrypted S3 state backend with locking and a key separate from every cloud deployment. Terraform manages inventory metadata only; it runs no remote commands. Omit `--inventory` below to consume its `ansible_inventory` output. Hand-written inventory must put each host directly under `all.children.nntmux.hosts`.

### Install and activate processing

```bash
python3 deploy/baremetal/deploy.py install --host indexer \
  --inventory /secure/hosts.yml --settings /secure/indexer-settings.yml \
  --vault-vars /secure/indexer-vault.yml
python3 deploy/baremetal/deploy.py verify --host indexer --inventory /secure/hosts.yml
```

The entrypoint selects one exact host and validates inputs before invoking Ansible. Add `--check` for a preview; Python bootstrap, builds, database changes, certificate issuance, and lifecycle operations are skipped. A preview does not demonstrate a working install, and an unbootstrapped host still needs Python for facts. `--diff` suppresses secret-bearing tasks. Installation reruns preserve application identity, database contents, group settings, and processing activation. Use `update` to change the deployed commit. A populated database without a successful managed install marker is refused; the installer never runs `migrate:fresh` or reseeds an installed database.

The website, Horizon, scheduler, renewal timer, and backup timer start after initialization. Usenet groups remain inactive and the tmux service remains disabled. Log in as the configured administrator, select groups and processing settings, and then start processing:

```bash
python3 deploy/baremetal/deploy.py processing-start --host indexer --inventory /secure/hosts.yml
python3 deploy/baremetal/deploy.py processing-stop --host indexer --inventory /secure/hosts.yml
```

Processing start enables reboot recovery; stop disables it. The tmux session belongs to `nntmux` and is named `nntmux`. Attach on the server with `sudo -u nntmux tmux attach -t nntmux`. Use `journalctl -u nntmux-horizon -u nntmux-tmux -u nntmux-scheduler` for service output. Application logs live under `/srv/nntmux/shared/storage/logs`.

### Updates, backups, and recovery

```bash
python3 deploy/baremetal/deploy.py update --host indexer --inventory /secure/hosts.yml \
  --settings /secure/indexer-settings.yml --vault-vars /secure/indexer-vault.yml \
  --commit FULL_40_CHARACTER_NEW_COMMIT
python3 deploy/baremetal/deploy.py rollback --host indexer --inventory /secure/hosts.yml \
  --commit FULL_40_CHARACTER_PREVIOUS_COMMIT --schema-compatible
python3 deploy/baremetal/deploy.py backup --host indexer --inventory /secure/hosts.yml
```

Updates build a separate release, enter maintenance, stop writers, take a consistent backup, run ordinary migrations without seeding, switch the current symlink, and restart previously active services. Completed release configuration is immutable. Application rollback requires an explicitly schema-compatible, previously built release; it cannot reverse database migrations. A migration or activation failure leaves maintenance enabled and writers stopped. Inspect `/etc/nntmux/operation-state.json` and protected service logs before recovering; use a schema-compatible rollback or restore the recorded backup for incompatible changes.

Nightly restic backups run around 03:00 server time with up to five minutes of jitter. Backups stop web/application writers and data services for the **entire capture window**. Retention keeps seven daily, four weekly, and six monthly snapshots. Backups include native service data, application releases/files, configuration, certificates, and a package/version manifest. Backup upload failure attempts to restore the previous service state and still reports failure. Inspect `journalctl -u nntmux-backup`, `/etc/nntmux/last-backup.json`, and verification output for backup age. Test renewal with `sudo certbot renew --dry-run` during physical-server acceptance testing.

Restore requires an explicit snapshot ID and the same hostname/search engine. The restore playbook prepares host packages and configuration, retrieves the manifest, restores exact recorded runtime package versions, and verifies restored services before activation:

```bash
python3 deploy/baremetal/deploy.py restore --host indexer --inventory /secure/hosts.yml \
  --settings /secure/indexer-settings.yml --vault-vars /secure/indexer-vault.yml \
  --snapshot EXPLICIT_SNAPSHOT_ID --move-data-aside
```

`--move-data-aside` explicitly authorizes preserving existing managed data/configuration under `.before-restore-TIMESTAMP` names before replacement. It is normally needed even on a replacement server because package installation creates initial data directories. Reserve enough space for the existing data, downloaded snapshot, and restored files. Restore does not delete the preserved copies. Failures leave application writers stopped for explicit recovery. Restore uses the snapshot's original APP_KEY and credentials, so preserve the corresponding encrypted Vault file and restic password separately.

### Validation

Run Terraform formatting/validation/TFLint/tests, YAML and Ansible lint, syntax checks for every playbook, `python3 -m unittest discover -s deploy/baremetal/tests -v`, and the deployment initialization PHPUnit tests. CI checks native tooling independently of existing cloud deployments. Automated checks use mocked services/APIs; they do not establish physical-server acceptance. Before production use, exercise fresh installation, rerun, reboot, admin login, queue/scheduler work, certificate renewal, manual NNTP activation, update failure, rollback, and backup/restore on disposable physical servers for **both** search engines.
