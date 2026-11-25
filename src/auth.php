<?php
// Sign-in by emailed link. No passwords are stored anywhere.
declare(strict_types=1);

const SESSION_COOKIE = 'hf_session';

function token_hash(string $token): string
{
    return hash('sha256', $token);
}

// How long a sign-in link sent by the organizer (the Members page) lasts.
const ORGANIZER_LINK_DAYS = 7;

/**
 * Emails a sign-in link if the address belongs to an active member. Returns whether one went out.
 * A link the member asks for themselves works once, for a short time. One the organizer
 * sends from the Members page ($fromOrganizer) lasts a week, because the member isn't
 * waiting for it and may not read it straight away. The same goes for the link sent to
 * someone a member has just added to their household; $intro then says who added them.
 */
function send_login_link(string $email, bool $fromOrganizer = false, string $intro = ''): bool
{
    $member = q('SELECT * FROM members WHERE email = ? AND active = 1', [trim($email)])->fetch();
    if (!$member) {
        return false; // The sign-in page says nothing either way, so it can't be used to test who is a member.
    }
    $token = bin2hex(random_bytes(32));
    $minutes = $fromOrganizer ? ORGANIZER_LINK_DAYS * 1440 : (int) config('login_link_minutes');
    q('INSERT INTO login_tokens (token_hash, member_id, expires_at) VALUES (?, ?, ?)',
        [token_hash($token), $member['id'], now_utc($minutes * 60)]);

    $link = config('base_url') . '/?a=verify&t=' . $token;
    $body = "Hi " . first_name($member) . ",\n\n"
        . ($intro !== '' ? $intro . "\n\n" : '')
        . "Here is your sign-in link for " . config('site_name') . ":\n\n$link\n\n"
        . ($fromOrganizer
            ? "It works once, any time in the next " . ORGANIZER_LINK_DAYS . " days. There is no password to remember.\n"
            : "It works once and expires in $minutes minutes. If you didn't ask for it, you can ignore this email.\n");
    return send_email($member['email'], 'Your ' . config('site_name') . ' sign-in link', $body);
}

/**
 * Is this sign-in token still good? Returns the member id, or null.
 * There are two kinds of link. The usual one, asked for on the sign-in page, works once
 * and for a short time. The one in a "time to order" email (it has a cycle_id) works as
 * often as needed, on any device, for as long as that month is open.
 */
function check_login_token(string $token): ?int
{
    $row = q("SELECT t.member_id FROM login_tokens t LEFT JOIN cycles c ON c.id = t.cycle_id
              WHERE t.token_hash = ? AND t.expires_at > ?
                AND ((t.cycle_id IS NULL AND t.used_at IS NULL) OR c.status = 'open')",
        [token_hash($token), now_utc()])->fetch();
    return $row ? (int) $row['member_id'] : null;
}

/** Uses up the token and signs this device in. */
function complete_login(string $token): bool
{
    $memberId = check_login_token($token);
    if ($memberId === null) {
        return false;
    }
    // A one-time link is used up here. A month's link (cycle_id set) is left to work again.
    q('UPDATE login_tokens SET used_at = ? WHERE token_hash = ? AND cycle_id IS NULL', [now_utc(), token_hash($token)]);

    $session = bin2hex(random_bytes(32));
    $seconds = (int) config('session_days') * 86400;
    q('INSERT INTO sessions (token_hash, member_id, expires_at) VALUES (?, ?, ?)',
        [token_hash($session), $memberId, now_utc($seconds)]);
    setcookie(SESSION_COOKIE, $session, [
        'expires'  => time() + $seconds,
        'path'     => '/',
        'secure'   => str_starts_with((string) config('base_url'), 'https://'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    return true;
}

/** The signed-in member (with household name), or null. */
function current_member(): ?array
{
    static $member = false;
    if ($member !== false) {
        return $member;
    }
    $member = null;
    $session = $_COOKIE[SESSION_COOKIE] ?? '';
    if ($session !== '') {
        $row = q('SELECT m.*, h.name AS household_name
                  FROM sessions s
                  JOIN members m ON m.id = s.member_id
                  JOIN households h ON h.id = m.household_id
                  WHERE s.token_hash = ? AND s.expires_at > ? AND m.active = 1',
            [token_hash($session), now_utc()])->fetch();
        if ($row) {
            q('UPDATE sessions SET last_seen_at = ? WHERE token_hash = ?', [now_utc(), token_hash($session)]);
            $member = $row;
        }
    }
    return $member;
}

function logout(): void
{
    $session = $_COOKIE[SESSION_COOKIE] ?? '';
    if ($session !== '') {
        q('DELETE FROM sessions WHERE token_hash = ?', [token_hash($session)]);
    }
    setcookie(SESSION_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);
}

/** A hidden value added to every form so other sites can't submit forms on a member's behalf. */
function csrf_token(): string
{
    return hash_hmac('sha256', 'csrf', $_COOKIE[SESSION_COOKIE] ?? '');
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function require_csrf(): void
{
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('This form has expired. Please go back, reload the page and try again.');
    }
}
