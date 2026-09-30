// Small, dependency-free helpers: ids, sampling, URL and text sanitizing.
//
// The browser sends telemetry straight to ZipLogger, so anything sensitive must be removed HERE,
// before the SDK sees it. ZipLogger's own server-side rules are a second line of defence, not the first.

const HEX = '0123456789abcdef';

/** Cryptographically random lowercase hex of `bytes` bytes. */
export function randomHex(bytes) {
  const buf = new Uint8Array(bytes);
  const c = typeof crypto !== 'undefined' ? crypto : (typeof window !== 'undefined' ? window.crypto : null);
  if (c && c.getRandomValues) {
    c.getRandomValues(buf);
  } else {
    for (let i = 0; i < bytes; i++) buf[i] = Math.floor(Math.random() * 256);
  }
  let out = '';
  for (let i = 0; i < buf.length; i++) out += HEX[buf[i] >> 4] + HEX[buf[i] & 15];
  return out;
}

/** Random opaque id in the SDK's own shape: "<prefix>_<20 hex>". */
export function randomId(prefix) {
  return prefix + '_' + randomHex(10);
}

/** FNV-1a hash of a string mapped to [0, 1). Deterministic: the same key always gives the same value. */
export function fraction(key) {
  let h = 0x811c9dc5;
  const s = String(key);
  for (let i = 0; i < s.length; i++) {
    h ^= s.charCodeAt(i);
    h = Math.imul(h, 0x01000193) >>> 0;
  }
  return h / 0x100000000;
}

/** Deterministic sampling: is `key` inside `percent` (0..100)? */
export function sampledIn(key, percent) {
  const p = Number(percent);
  if (!(p > 0)) return false;
  if (p >= 100) return true;
  return fraction(key) * 100 < p;
}

/** Wrap a function so an exception inside it can never reach the page. */
export function guard(fn, fallback) {
  return function guarded() {
    try {
      return fn.apply(this, arguments);
    } catch (e) {
      return fallback;
    }
  };
}

/** True when `value` is a non-empty string no longer than `max`. */
export function isText(value, max) {
  return typeof value === 'string' && value.length > 0 && value.length <= (max || 4096);
}

// ---------------------------------------------------------------------------------------------
// Sensitive names.
// ---------------------------------------------------------------------------------------------

const KEY_FRAGMENTS = ['password', 'passwd', 'passphrase', 'secret', 'token', 'apikey', 'authorization', 'cookie', 'credential',
  'privatekey', 'accesskey', 'signature', 'bearer', 'sessionid', 'phpsessid', 'creditcard', 'cardnumber', 'email', 'username',
  'userlogin', 'ipaddress', 'remoteaddr', 'forwardedfor', 'clientip', 'phone', 'csrf', 'xsrf', 'nonce'];
const KEY_TOKENS = new Set(['pwd', 'pw', 'pass', 'auth', 'session', 'sid', 'login', 'ip', 'otp', 'pin', 'card', 'cvv', 'cvc', 'ssn', 'iban', 'key']);

/** Does a property or field NAME suggest a secret or personal value? */
export function isSensitiveKey(key) {
  if (typeof key !== 'string' || !key) return false;
  const alnum = key.toLowerCase().replace(/[^a-z0-9]/g, '');
  for (let i = 0; i < KEY_FRAGMENTS.length; i++) if (alnum.indexOf(KEY_FRAGMENTS[i]) !== -1) return true;
  const tokens = key.replace(/([a-z0-9])([A-Z])/g, '$1 $2').split(/[^A-Za-z0-9]+/).filter(Boolean);
  for (let i = 0; i < tokens.length; i++) if (KEY_TOKENS.has(tokens[i].toLowerCase())) return true;
  return false;
}

// ---------------------------------------------------------------------------------------------
// URLs.
// ---------------------------------------------------------------------------------------------

/**
 * Resolve a possibly-relative URL against the page. Returns a URL object or null.
 * Never throws.
 */
export function parseUrl(input, base) {
  try {
    const b = base || (typeof location !== 'undefined' ? location.href : 'http://localhost/');
    return new URL(String(input), b);
  } catch (e) {
    return null;
  }
}

/** Replace identifier-looking path segments with ":id" so paths group and do not leak ids. */
export function normalizePath(pathname) {
  return String(pathname || '/')
    .split('/')
    .map(function (seg) {
      if (/^\d{2,}$/.test(seg)) return ':id';
      if (/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(seg)) return ':id';
      if (/^[0-9a-f]{12,}$/i.test(seg)) return ':id';
      return seg;
    })
    .join('/');
}

/**
 * The path of a URL: no scheme, host, credentials, query string or fragment, at most 200 characters.
 * "/wp-admin/admin-ajax.php?action=x&token=y#z" becomes "/wp-admin/admin-ajax.php".
 */
export function safePath(input, options) {
  const u = parseUrl(input);
  if (!u) return '/';
  let p = u.pathname || '/';
  if (options && options.normalize) p = normalizePath(p);
  return p.length > 200 ? p.slice(0, 200) : p;
}

/** Scheme, host and path only: the form in which a URL may leave the page. */
export function safeUrl(input) {
  const u = parseUrl(input);
  if (!u) return '';
  return u.origin === 'null' ? u.protocol + '//' + u.host + u.pathname : u.origin + u.pathname;
}

/** Host name of a URL (lower case), or ''. */
export function hostOf(input) {
  const u = parseUrl(input);
  return u ? u.hostname.toLowerCase() : '';
}

/** Same origin as the page? */
export function isSameOrigin(input) {
  const u = parseUrl(input);
  return !!u && typeof location !== 'undefined' && u.origin === location.origin;
}

/**
 * Does a path match a pattern? "/members/*" matches "/members/x/y"; "/private" matches "/private" and
 * "/private/anything" (a whole segment prefix, never "/privateer").
 */
export function pathMatches(pattern, path) {
  if (typeof pattern !== 'string' || typeof path !== 'string') return false;
  const p = pattern.trim();
  if (!p) return false;
  if (p.indexOf('*') === -1) return path === p || path === p + '/' || path.indexOf(p.replace(/\/$/, '') + '/') === 0;
  const re = new RegExp('^' + p.replace(/[.+?^${}()|[\]\\]/g, '\\$&').replace(/\*/g, '.*') + '$');
  return re.test(path);
}

// ---------------------------------------------------------------------------------------------
// Text.
// ---------------------------------------------------------------------------------------------

const SECRET_WORDS = 'password|passwd|passphrase|pwd|secret|token|api[_-]?key|apikey|authorization|credentials?|private[_-]?key|access[_-]?key|session[_-]?id|phpsessid|signature|cvv|cvc|otp|nonce';

/** Truncate at a character boundary, marking the cut. */
export function truncate(text, max) {
  const s = String(text);
  return s.length <= max ? s : s.slice(0, Math.max(0, max - 1)) + '…';
}

function luhn(digits) {
  let sum = 0;
  let alt = false;
  for (let i = digits.length - 1; i >= 0; i--) {
    let n = digits.charCodeAt(i) - 48;
    if (alt) { n *= 2; if (n > 9) n -= 9; }
    sum += n;
    alt = !alt;
  }
  return sum % 10 === 0;
}

/**
 * Remove what must not leave the page from free text (an error message, a stack trace): credentials
 * and query strings in URLs, emails, IP addresses, tokens and keys, "secret=value" pairs, card numbers.
 * Best effort: it reduces exposure, it cannot guarantee that no secret survives arbitrary text.
 */
export function scrubText(text, max) {
  let s = String(text == null ? '' : text);
  if (s.length > (max || 2048) * 4) s = s.slice(0, (max || 2048) * 4);

  s = s.replace(/-----BEGIN [A-Z ]*PRIVATE KEY-----[\s\S]*?(?:-----END [A-Z ]*PRIVATE KEY-----|$)/g, '[redacted private key]');
  s = s.replace(/\b(Set-Cookie|Cookie|Proxy-Authorization|Authorization|X-Api-Key)\s*:[ \t]*[^\r\n]*/gi, '$1: [redacted]');
  s = s.replace(/\bBearer\s+[A-Za-z0-9._~+/=-]{8,}/gi, 'Bearer [redacted]');
  s = s.replace(/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*/g, '[redacted]');
  s = s.replace(/\bzk_[A-Za-z0-9_-]{8,}/g, '[redacted]')
    .replace(/\b(?:sk|pk|rk)_(?:live|test)_[A-Za-z0-9]{8,}/g, '[redacted]')
    .replace(/\bAKIA[0-9A-Z]{16}\b/g, '[redacted]')
    .replace(/\bgh[pousr]_[A-Za-z0-9]{20,}/g, '[redacted]')
    .replace(/\bxox[abprs]-[A-Za-z0-9-]{10,}/g, '[redacted]')
    .replace(/\b(?:wordpress_(?:logged_in|sec|test_cookie)|wp-settings(?:-time)?)[\w-]*=[^\s;,'"]+/gi, '[redacted]');

  // key=value / key: value / "key":"value" where the key names something sensitive.
  s = s.replace(new RegExp('(["\']?)\\b([A-Za-z0-9_-]*(?:' + SECRET_WORDS + ')[A-Za-z0-9_-]*)\\1(\\s*(?:=>|[=:])\\s*)("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|[^\\s,;&)}\\]]+)', 'gi'),
    function (m, q, key, sep, value) {
      return value === '[redacted]' || value === '"[redacted]"' ? m : q + key + q + sep + '[redacted]';
    });

  // URLs: keep scheme://host/path only.
  s = s.replace(/\b([a-z][a-z0-9+.-]{1,15}):\/\/[^\s'"<>()[\]{}|\\^`]+/gi, function (m) {
    let url = m;
    let trailing = '';
    const t = /[.,;:!]+$/.exec(url);
    if (t) { trailing = t[0]; url = url.slice(0, -trailing.length); }
    const u = parseUrl(url, 'http://invalid.invalid/');
    if (!u || !u.host) return '[url]' + trailing;
    return u.protocol + '//' + u.host + u.pathname + trailing;
  });
  // Relative "?a=b" tails.
  // (No regex look-behind anywhere in this file: Safari before 16.4 rejects it at parse time, which would
  // break the whole script. A captured prefix does the same job.)
  s = s.replace(/([\w/.-])\?[A-Za-z0-9_\-[\]%.]+=[^\s'"<>]*/g, '$1');

  s = s.replace(/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}/g, '[email]');
  s = s.replace(/(^|[^\d.])(?:(?:25[0-5]|2[0-4]\d|1?\d?\d)\.){3}(?:25[0-5]|2[0-4]\d|1?\d?\d)(?!\.?\d)/g, '$1[ip]');
  s = s.replace(/(^|[^\w:])(?:[0-9a-fA-F]{1,4}:){3,7}[0-9a-fA-F]{1,4}(?![\w:])/g, '$1[ip]');
  s = s.replace(/(^|[^\d-])((?:\d[ -]?){12,18}\d)(?![\d-])/g, function (m, pre, num) {
    const digits = num.replace(/\D/g, '');
    return digits.length >= 13 && digits.length <= 19 && luhn(digits) ? pre + '[card]' : m;
  });

  return truncate(s, max || 2048);
}

/** Sanitize a stack trace: scrub each line and keep the frames, limited in count and size. */
export function scrubStack(stack, maxFrames, maxChars) {
  if (typeof stack !== 'string' || !stack) return undefined;
  const lines = stack.split('\n').slice(0, maxFrames || 30).map(function (line) { return scrubText(line, 300); });
  return truncate(lines.join('\n'), maxChars || 6000);
}

/**
 * Sanitize a flat properties object for an analytics event: primitives only, bounded, sensitive
 * names dropped, strings scrubbed.
 */
export function cleanProperties(props, maxKeys) {
  const out = {};
  if (!props || typeof props !== 'object' || Array.isArray(props)) return out;
  let n = 0;
  for (const key of Object.keys(props)) {
    if (n >= (maxKeys || 30)) break;
    if (!/^[A-Za-z0-9_.:$-]{1,60}$/.test(key) || isSensitiveKey(key)) continue;
    const v = props[key];
    if (typeof v === 'string') out[key] = scrubText(v, 200);
    else if (typeof v === 'number' && isFinite(v)) out[key] = v;
    else if (typeof v === 'boolean') out[key] = v;
    else continue;
    n++;
  }
  return out;
}

/** An analytics event name the server will keep: lower-case, [a-z0-9_.:$], at most 120 characters. */
export function eventName(name) {
  if (typeof name !== 'string') return '';
  return name.trim().toLowerCase().replace(/[\s-]+/g, '_').replace(/[^a-z0-9_.:$]/g, '').replace(/_+/g, '_').replace(/^_|_$/g, '').slice(0, 120);
}
