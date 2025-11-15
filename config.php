<?php
declare(strict_types=1);

/**
 * 全局配置（安全加固版）
 * webhook.php / verify.php 都会 include 本文件
 */

// ==================== 调试开关（生产务必 false） ====================
const DEBUG = false; // 开发可改 true

// ==================== Telegram Bot 基本配置 ====================
const BOT_TOKEN    = 'YOUR_TELEGRAM_BOT_TOKEN';
const BOT_USERNAME = 'YourBotUserName';   // 不带 @，如 MyAntiSpamBot
const ADMIN_ID     = 123456789;           // 你的 Telegram 数字 ID

// 仅私聊 & 拒绝其他 Bot 主动来信
const ALLOW_BOT_INITIATED = false;

// ==================== Webhook 来源校验（强制） ====================
// setWebhook 时使用同一密钥：?secret_token=CHANGE_TO_A_LONG_RANDOM_SECRET
const TG_WEBHOOK_SECRET        = 'CHANGE_TO_A_LONG_RANDOM_SECRET';
const ENFORCE_WEBHOOK_SECRET   = true; // true=强制校验请求头

// ==================== 数据与状态文件（移出 Web 根） ====================
const DATA_DIR = __DIR__ . '/botdata'; // Bot数据目录，建议修改
const VERIFIED_USERS_FILE = DATA_DIR . '/verified_users.json';
const ROUTE_MAP_FILE      = DATA_DIR . '/routes.json';
const BANNED_USERS_FILE   = DATA_DIR . '/banned_users.json';
const RATE_LIMIT_FILE     = DATA_DIR . '/rate_limit.json';

// 路由表清理策略（webhook.php 的 route_save 会用到）
const ROUTE_TTL_SECONDS = 7 * 24 * 60 * 60; // 7 天内未使用的路由记录会被清理
const ROUTE_MAX_ENTRIES = 20000;           // 最多保留 2 万条路由映射

// ==================== 速率限制（防风暴/DoS） ====================
const RATE_LIMIT_ENABLED     = true;
const RATE_LIMIT_WINDOW_SEC  = 10;    // 滑窗：10 秒
const RATE_LIMIT_MAX_EVENTS  = 30;    // 每窗最多 30 条

// ==================== JWT / 验证配置 ====================
const SHARED_JWT_SECRET      = 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET';
const VERIFICATION_TOKEN_TTL = 600;   // 验证 JWT 有效期（秒）
const JWT_LEEWAY             = 300;   // 解码容忍（秒）

// ==================== reCAPTCHA 配置 ====================
const RECAPTCHA_SITE_KEY   = 'YOUR_RECAPTCHA_SITE_KEY';
const RECAPTCHA_SECRET_KEY = 'YOUR_RECAPTCHA_SECRET_KEY';
const VERIFY_URL           = 'https://yourdomain.com/verify.php'; // HTTPS

// ==================== 屏蔽词（文件可在 Web 根外；为空则忽略） ====================
const BAD_WORDS_FILE             = DATA_DIR . '/bad_words.txt';
const BAD_WORDS_IGNORE_CASE      = true;
const BAD_WORDS_ENABLE_WILDCARD  = true;
const BAD_WORDS_ENABLE_REGEX     = false;

// ==================== 全局 PHP 错误/日志 ====================
if (DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR);
}
ini_set('log_errors', '1');

/** 仅在 DEBUG 写入 error_log 的便捷函数 */
function debug_log(string $message): void
{
    if (DEBUG) error_log($message);
}

/** 确保目录存在 */
function ensure_dir(string $dir): void
{
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
}
