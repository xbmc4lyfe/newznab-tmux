//! Category ids, as seeded in the `categories` table.

pub const OTHER_MISC: i32 = 10;
pub const OTHER_HASHED: i32 = 20;
pub const GAME_NDS: i32 = 1010;
pub const GAME_PSP: i32 = 1020;
pub const GAME_WII: i32 = 1030;
pub const GAME_XBOX: i32 = 1040;
pub const GAME_XBOX360: i32 = 1050;
pub const GAME_WIIWARE: i32 = 1060;
pub const GAME_XBOX360DLC: i32 = 1070;
pub const GAME_PS3: i32 = 1080;
pub const GAME_OTHER: i32 = 1999;
pub const GAME_3DS: i32 = 1110;
pub const GAME_PSVITA: i32 = 1120;
pub const GAME_WIIU: i32 = 1130;
pub const GAME_XBOXONE: i32 = 1140;
pub const GAME_PS4: i32 = 1180;
pub const MOVIE_FOREIGN: i32 = 2010;
pub const MOVIE_OTHER: i32 = 2999;
pub const MOVIE_SD: i32 = 2030;
pub const MOVIE_HD: i32 = 2040;
pub const MOVIE_UHD: i32 = 2045;
pub const MOVIE_3D: i32 = 2050;
pub const MOVIE_BLURAY: i32 = 2060;
pub const MOVIE_DVD: i32 = 2070;
pub const MOVIE_WEBDL: i32 = 2080;
pub const MOVIE_X265: i32 = 2090;
pub const MUSIC_MP3: i32 = 3010;
pub const MUSIC_VIDEO: i32 = 3020;
pub const MUSIC_AUDIOBOOK: i32 = 3030;
pub const MUSIC_LOSSLESS: i32 = 3040;
pub const MUSIC_PODCAST: i32 = 3050;
pub const MUSIC_OTHER: i32 = 3999;
pub const MUSIC_FOREIGN: i32 = 3060;
pub const PC_0DAY: i32 = 4010;
pub const PC_ISO: i32 = 4020;
pub const PC_MAC: i32 = 4030;
pub const PC_PHONE_OTHER: i32 = 4040;
pub const PC_GAMES: i32 = 4050;
pub const PC_PHONE_IOS: i32 = 4060;
pub const PC_PHONE_ANDROID: i32 = 4070;
pub const TV_WEBDL: i32 = 5010;
pub const TV_FOREIGN: i32 = 5020;
pub const TV_SD: i32 = 5030;
pub const TV_HD: i32 = 5040;
pub const TV_UHD: i32 = 5045;
pub const TV_OTHER: i32 = 5999;
pub const TV_SPORT: i32 = 5060;
pub const TV_ANIME: i32 = 5070;
pub const TV_DOCU: i32 = 5080;
pub const TV_X265: i32 = 5090;
pub const XXX_DVD: i32 = 6010;
pub const XXX_WMV: i32 = 6020;
pub const XXX_XVID: i32 = 6030;
pub const XXX_X264: i32 = 6040;
pub const XXX_CLIPHD: i32 = 6041;
pub const XXX_CLIPSD: i32 = 6042;
pub const XXX_UHD: i32 = 6045;
pub const XXX_VR: i32 = 6046;
pub const XXX_ONLYFANS: i32 = 6047;
pub const XXX_PACK: i32 = 6050;
pub const XXX_IMAGESET: i32 = 6060;
pub const XXX_OTHER: i32 = 6999;
pub const XXX_SD: i32 = 6080;
pub const XXX_WEBDL: i32 = 6090;
pub const BOOKS_MAGAZINES: i32 = 7010;
pub const BOOKS_EBOOK: i32 = 7020;
pub const BOOKS_COMICS: i32 = 7030;
pub const BOOKS_TECHNICAL: i32 = 7040;
pub const BOOKS_FOREIGN: i32 = 7060;
pub const BOOKS_UNKNOWN: i32 = 7999;
pub const OTHER_ROOT: i32 = 1;
pub const GAME_ROOT: i32 = 1000;
pub const MOVIE_ROOT: i32 = 2000;
pub const MUSIC_ROOT: i32 = 3000;
pub const PC_ROOT: i32 = 4000;
pub const TV_ROOT: i32 = 5000;
pub const XXX_ROOT: i32 = 6000;
pub const BOOKS_ROOT: i32 = 7000;
pub const STATUS_INACTIVE: i32 = 0;
pub const STATUS_ACTIVE: i32 = 1;
pub const STATUS_DISABLED: i32 = 2;

/// `Category::OTHERS_GROUP`.
pub const OTHERS_GROUP: [i32; 9] = [
    BOOKS_UNKNOWN,
    GAME_OTHER,
    MOVIE_OTHER,
    MUSIC_OTHER,
    PC_PHONE_OTHER,
    TV_OTHER,
    OTHER_HASHED,
    XXX_OTHER,
    OTHER_MISC,
];
