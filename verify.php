<?php
declare(strict_types=1);

/**
 * 验证页（安全加固版）
 * - 完成 reCAPTCHA 后，只提供 “/start <token> 一键复制”，不跳转 Deep Link
 * - 加强：校验 Google 返回的 hostname 与本站一致；更严响应头（HSTS/Cache-Control/Permissions-Policy）
 */

require __DIR__ . '/config.php';
require __DIR__ . '/vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

JWT::$leeway = JWT_LEEWAY;

// 安全响应头
header('Content-Type: text/html; charset=UTF-8');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; frame-src https://www.google.com https://recaptcha.google.com; script-src 'self' https://www.google.com https://www.gstatic.com;");
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
// 仅 HTTPS 部署时启用 HSTS
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
}
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function debug_php_time_block(string $label='当前'): string {
    if (!DEBUG) return '';
    $unix=time();
    $iso=(new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get())))->format(DateTimeInterface::ATOM);
    return "<div class=\"token-box mono\">[DEBUG] {$label} PHP time(): {$unix}<br>[DEBUG] {$label} PHP ISO: {$iso}</div>";
}
function render_page(string $title, string $bodyHtml, bool $loadRecaptchaJs=false): void {
    $recaptcha = $loadRecaptchaJs ? "<script src='https://www.google.com/recaptcha/api.js' async defer></script>" : "";
    $footer = DEBUG ? 'Powered by reCAPTCHA · DEBUG 模式已开启。' : 'Powered by reCAPTCHA.';
    echo <<<HTML
<!doctype html>
<html lang="zh-CN"><head>
<meta charset="utf-8"><title>{$title}</title><meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--bg:#0f172a;--fg:#e5e7eb;--muted:#9ca3af;--acc:#22c55e;--err:#ef4444;--card:#111827;}
*{box-sizing:border-box} body{margin:0;font-family:system-ui,-apple-system,segoe ui,Roboto,ubuntu,arial;background:linear-gradient(160deg,#0b1020,#10172a);color:var(--fg);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
.card{width:min(720px,100%);background:rgba(17,24,39,.85);border:1px solid rgba(255,255,255,.06);border-radius:16px;padding:28px;box-shadow:0 10px 40px rgba(0,0,0,.4);backdrop-filter: blur(4px);}
h1{font-size:20px;margin:0 0 12px} p{line-height:1.6;color:var(--muted)}
.btn{display:inline-block;padding:12px 18px;border-radius:12px;background:var(--acc);color:#06110a;text-decoration:none;font-weight:600;border:0;cursor:pointer}
.error{color:var(--err);font-weight:600}.sep{height:1px;background:rgba(255,255,255,.08);margin:18px 0}
code,.mono{font-family:ui-monospace,SFMono-Regular,Menlo,monospace}
.token-box{word-break:break-all;background:#0b1020;border:1px dashed rgba(255,255,255,.12);border-radius:8px;padding:8px 10px;color:#ddd;margin-top:8px;}
footer{margin-top:16px;font-size:12px;color:#8b96a8}
</style></head><body>
<div class="card">{$bodyHtml}<footer>{$footer}</footer></div>
{$recaptcha}
</body></html>
HTML;
    exit;
}
function render_error(string $message, string $title='验证出错'): void {
    $debug = debug_php_time_block('错误发生时');
    $body = "<h1>⚠️ ".h($title)."</h1><p class='error'>".h($message)."</p><div class='sep'></div><p>请返回 Telegram 重新获取验证链接并重试。</p>{$debug}";
    render_page($title, $body, false);
}
function render_captcha_form(string $siteKey, string $verifyJwt, array $verifyPayload): void {
    $debug = debug_php_time_block('页面加载时');
    $expInfo = '';
    if (DEBUG && isset($verifyPayload['exp'])) {
        $exp=(int)$verifyPayload['exp']; $expIso=date(DATE_ATOM,$exp);
        $expInfo = "<div class=\"token-box mono\">[DEBUG] 验证 JWT exp_ts: {$exp}<br>[DEBUG] 验证 JWT exp_iso: {$expIso}</div>";
    }
    $body = <<<HTML
<h1>🤖 人机验证</h1>
<p>请完成下方的 Google reCAPTCHA 验证，以继续与机器人对话。</p>
<div class="sep"></div>
<form method="post" action="" autocomplete="off">
  <input type="hidden" name="verify_token" value="{$verifyJwt}">
  <div class="g-recaptcha" data-sitekey="{$siteKey}"></div>
  <div style="height:14px"></div>
  <button type="submit" class="btn">验证并继续</button>
</form>
<div class="sep"></div>
{$debug}
{$expInfo}
HTML;
    render_page('人机验证', $body, true);
}
function render_success(string $botUsername, string $successJwt, int $expTs): void {
    $cmd = "/start ".$successJwt;
    $debugNow = debug_php_time_block('生成成功 JWT 时');
    $expInfo=''; if (DEBUG) { $expIso=date(DATE_ATOM,$expTs); $expInfo="<div class=\"token-box mono\">[DEBUG] 成功 JWT exp_ts: {$expTs}<br>[DEBUG] 成功 JWT exp_iso: {$expIso}</div>"; }
    $body = <<<HTML
<h1>✅ 验证成功</h1>
<p>点击下方按钮复制指令，切换回 Telegram 与 <b>@{$botUsername}</b> 的对话，<b>粘贴并发送</b>即可完成验证。</p>
<div class="token-box mono" id="cmdBox">{$cmd}</div>
<div style="height:12px"></div>
<button class="btn" onclick="copyCmd()">一键复制指令</button>
<div class="sep"></div>
{$debugNow}
{$expInfo}
<script>
function copyCmd(){
  var t=document.getElementById('cmdBox').innerText;
  if(navigator.clipboard&&navigator.clipboard.writeText){
    navigator.clipboard.writeText(t).then(()=>alert('已复制到剪贴板。请回到 Telegram 粘贴并发送。'),()=>fallbackCopy(t));
  }else fallbackCopy(t);
}
function fallbackCopy(text){
  var ta=document.createElement('textarea'); ta.value=text; document.body.appendChild(ta); ta.select();
  try{ document.execCommand('copy'); alert('已复制到剪贴板。请回到 Telegram 粘贴并发送。'); }catch(e){ prompt('复制失败，请手动复制：', text); }
  document.body.removeChild(ta);
}
</script>
HTML;
    render_page('验证成功', $body, false);
}

// ------------- 主逻辑 -------------
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

try {
    if ($method === 'GET') {
        $token = $_GET['token'] ?? '';
        if (!$token) render_error('缺少 token 参数。');

        try { $obj = JWT::decode($token, new Key(SHARED_JWT_SECRET,'HS256')); }
        catch (\Firebase\JWT\ExpiredException $e){ render_error(DEBUG?('链接已过期：'.$e->getMessage()):'链接已过期，请回到 Telegram 重新获取验证链接。'); }
        catch (\Throwable $e){ render_error(DEBUG?('无效的链接或 token：'.$e->getMessage()):'无效的链接或 token。'); }

        $data = json_decode(json_encode($obj), true) ?: [];
        if (($data['type']??null)!=='verify' || !isset($data['user_id'])) render_error('token 类型不正确或缺少 user_id。');

        render_captcha_form(RECAPTCHA_SITE_KEY, $token, $data);
    }

    if ($method === 'POST') {
        $gResp     = $_POST['g-recaptcha-response'] ?? '';
        $verifyJwt = $_POST['verify_token'] ?? '';
        if (!$gResp || !$verifyJwt) render_error('提交数据不完整（缺少验证码或令牌）。');

        try { $obj = JWT::decode($verifyJwt, new Key(SHARED_JWT_SECRET,'HS256')); }
        catch (\Firebase\JWT\ExpiredException $e){ render_error(DEBUG?('验证会话已过期：'.$e->getMessage()):'验证会话已过期，请回到 Telegram 重新获取验证链接。'); }
        catch (\Throwable $e){ render_error(DEBUG?('无效的验证令牌：'.$e->getMessage()):'无效的验证令牌。'); }

        $verifyData = json_decode(json_encode($obj), true) ?: [];
        if (($verifyData['type']??null)!=='verify' || !isset($verifyData['user_id'])) render_error('验证令牌类型不正确或缺少 user_id。');

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $payload = ['secret'=>RECAPTCHA_SECRET_KEY,'response'=>$gResp]; if ($ip) $payload['remoteip']=$ip;

        $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($payload),
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        // curl_close($ch);

        if ($resp === false || $http !== 200) render_error('验证码验证失败（网络错误），请重试。');

        $result = json_decode($resp, true);
        $hostOk = isset($result['hostname']) && is_string($result['hostname']) &&
                  in_array($result['hostname'], [$_SERVER['HTTP_HOST'] ?? '', parse_url(VERIFY_URL, PHP_URL_HOST)], true);

        if (!is_array($result) || !($result['success'] ?? false) || !$hostOk) {
            render_error('人机验证失败（域名校验未通过），请返回重试。');
        }

        $now=time(); $exp = isset($verifyData['exp']) ? (int)$verifyData['exp'] : ($now + VERIFICATION_TOKEN_TTL);
        $success = ['type'=>'success','user_id'=>(int)$verifyData['user_id'],'verified'=>true,'exp'=>$exp];
        $successJwt = JWT::encode($success, SHARED_JWT_SECRET, 'HS256');

        render_success(BOT_USERNAME, $successJwt, $exp);
    }

    render_error('不支持的请求方法。', '方法不被允许');
} catch (\Throwable $e) {
    render_error(DEBUG ? ('服务器内部错误：'.$e->getMessage()) : '服务器内部错误，请稍后再试。');
}
