<?php

declare(strict_types=1);

namespace BsCms\Install;

/**
 * Shared view renderer for the web installer.
 *
 * Renders a modern, step-based wizard shell matching the admin dashboard
 * design language (dark surface, stepper navigation, verification states).
 * All markup is self-contained (no external assets) and safe for shared
 * hosting / hardened CSP deployments.
 */
final class InstallerViews
{
    private const STEPS = ['System Requirements', 'Database', 'Owner Account', 'Finish'];

    /**
     * Renders the full wizard page.
     *
     * @param int $step 1..4 (active step)
     * @param string $title Page/step title
     * @param string $content Pre-escaped inner HTML for the step card
     * @param array{back?: string, extraHead?: string, bodyClass?: string} $opts
     */
    public static function shell(int $step, string $title, string $content, array $opts = []): string
    {
        $stepsHtml = '';
        foreach (self::STEPS as $idx => $label) {
            $n = $idx + 1;
            $state = 'upcoming';
            if ($n < $step) {
                $state = 'done';
            } elseif ($n === $step) {
                $state = 'active';
            }
            $marker = $n < $step ? '&#10003;' : (string) $n;
            $stepsHtml .= '<div class="step ' . $state . '">'
                . '<span class="dot">' . $marker . '</span>'
                . '<span class="lbl">' . htmlspecialchars($label) . '</span>'
                . '</div>';
            if ($n < count(self::STEPS)) {
                $stepsHtml .= '<div class="bar ' . ($n < $step ? 'done' : '') . '"></div>';
            }
        }

        $back = '';
        if (isset($opts['back']) && $opts['back'] !== '') {
            $back = '<div class="backrow"><a class="back" href="' . htmlspecialchars($opts['back']) . '">&larr; Back</a></div>';
        }

        $extraHead = $opts['extraHead'] ?? '';

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . htmlspecialchars($title) . ' — BS CMS Setup</title>'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<style>' . self::css() . '</style>'
            . $extraHead
            . '</head><body>'
            . '<div class="wrap">'
            . '<header class="brand"><span class="leaf">&#10087;</span><div><span class="bname">BS CMS</span><span class="btag">STUDIO SETUP</span></div></header>'
            . '<div class="stepper">' . $stepsHtml . '</div>'
            . $back
            . '<main class="card">'
            . '<h1>' . htmlspecialchars($title) . '</h1>'
            . $content
            . '</main>'
            . '<footer class="foot">Secure setup &middot; credentials are stored server-side and never echoed back</footer>'
            . '</div>'
            . ($opts['bodyEnd'] ?? '')
            . '</body></html>';
    }

    /** Themed error card page. */
    public static function errorPage(string $title, string $messageHtml, int $status, string $backTo = '/install', string $step = '2'): string
    {
        $content = '<div class="alert error"><strong>' . htmlspecialchars($title) . '</strong>'
            . '<div class="alertbody">' . $messageHtml . '</div></div>'
            . '<div class="backrow"><a class="btn ghost" href="' . htmlspecialchars($backTo) . '">Try again</a></div>';
        return self::shell((int) $step, $title, $content);
    }

    private static function css(): string
    {
        return <<<'CSS'
:root {
  --bg: #0B1220;
  --card: #141C2B;
  --card2: #101826;
  --border: #223043;
  --text: #E6EAF2;
  --muted: #8B94A7;
  --primary: #4C8DFF;
  --primary-h: #6AA0FF;
  --ok: #34D399;
  --warn: #FBBF24;
  --err: #F87171;
  --radius: 12px;
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
  background: radial-gradient(1100px 500px at 70% -10%, rgba(76,141,255,.14), transparent 60%),
              linear-gradient(180deg, #0B1220 0%, #0d1524 100%);
  color: var(--text);
  min-height: 100vh;
  line-height: 1.6;
  -webkit-font-smoothing: antialiased;
}
.wrap { max-width: 760px; margin: 0 auto; padding: 48px 20px 60px; }
.brand { display: flex; align-items: center; gap: 12px; margin-bottom: 36px; }
.leaf { font-size: 1.7rem; color: var(--ok); line-height: 1; }
.bname { display: block; font-weight: 700; font-size: 1.15rem; letter-spacing: .02em; }
.btag { display: block; font-size: .62rem; letter-spacing: .28em; color: var(--muted); }
.stepper { display: flex; align-items: center; margin-bottom: 28px; }
.step { display: flex; align-items: center; gap: 8px; }
.step .dot {
  width: 30px; height: 30px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: .8rem; font-weight: 600;
  background: var(--card2); color: var(--muted);
  border: 1px solid var(--border);
  flex-shrink: 0;
}
.step.active .dot { background: var(--primary); border-color: var(--primary); color: #fff; box-shadow: 0 0 0 4px rgba(76,141,255,.18); }
.step.done .dot { background: rgba(52,211,153,.15); border-color: var(--ok); color: var(--ok); }
.step .lbl { font-size: .82rem; color: var(--muted); white-space: nowrap; }
.step.active .lbl { color: var(--text); font-weight: 600; }
.bar { flex: 1; height: 2px; background: var(--border); margin: 0 12px; min-width: 16px; }
.bar.done { background: var(--ok); }
.card {
  background: var(--card);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 32px;
}
h1 { font-size: 1.45rem; margin: 0 0 20px; font-weight: 700; }
h2 { font-size: 1.05rem; margin: 22px 0 12px; }
h3 { font-size: .95rem; margin: 18px 0 8px; }
.checklist { list-style: none; margin: 0; padding: 0; }
.checklist li {
  display: flex; align-items: flex-start; gap: 10px;
  padding: 8px 0; border-bottom: 1px solid var(--border);
  font-size: .92rem;
}
.checklist li:last-child { border-bottom: none; }
.checklist .st { width: 18px; height: 18px; border-radius: 50%; flex-shrink: 0; margin-top: 3px;
  display: inline-flex; align-items: center; justify-content: center; font-size: .7rem; font-weight: 700; }
.st.pass { background: rgba(52,211,153,.15); color: var(--ok); }
.st.fail { background: rgba(248,113,113,.15); color: var(--err); }
.st.warn { background: rgba(251,191,36,.15); color: var(--warn); }
.pass { color: var(--ok); }
.fail { color: var(--err); font-weight: 600; }
.warn { color: var(--warn); }
label { display: block; font-size: .82rem; color: var(--muted); margin: 14px 0 4px; }
input {
  width: 100%; padding: 10px 12px; font-size: .92rem;
  background: var(--card2); color: var(--text);
  border: 1px solid var(--border); border-radius: 8px;
  outline: none; transition: border-color .15s;
}
input:focus { border-color: var(--primary); }
.btn {
  display: inline-flex; align-items: center; gap: 8px;
  background: var(--primary); color: #fff;
  padding: 12px 26px; border: none; border-radius: 8px;
  font-size: .95rem; font-weight: 600; cursor: pointer;
  text-decoration: none; transition: background .15s;
}
.btn:hover { background: var(--primary-h); }
.btn.ghost { background: transparent; border: 1px solid var(--border); color: var(--text); }
.btn.ghost:hover { border-color: var(--primary); color: var(--primary); }
.btn[disabled] { opacity: .55; cursor: not-allowed; }
.actions { margin-top: 26px; display: flex; justify-content: flex-end; gap: 12px; align-items: center; }
.backrow { margin-bottom: 14px; }
.back { color: var(--muted); text-decoration: none; font-size: .85rem; }
.back:hover { color: var(--text); }
.vpanel {
  background: var(--card2); border: 1px solid var(--border);
  border-radius: 10px; padding: 16px 18px; margin-bottom: 22px; font-size: .9rem;
}
.vrow { display: flex; align-items: center; gap: 10px; padding: 5px 0; }
.vrow .k { color: var(--muted); min-width: 120px; }
.vrow .v { font-family: ui-monospace, monospace; font-size: .85rem; }
.vrow .st { margin-left: auto; }
code {
  background: var(--card2); border: 1px solid var(--border);
  padding: 2px 8px; border-radius: 6px; font-size: .85rem;
  font-family: ui-monospace, monospace;
}
pre {
  background: var(--card2); border: 1px solid var(--border);
  padding: 16px; border-radius: 8px; overflow-x: auto;
  font-size: .85rem; margin: 12px 0;
}
.alert { border-radius: 10px; padding: 16px 18px; margin-bottom: 20px; font-size: .92rem; }
.alert.error { background: rgba(248,113,113,.1); border: 1px solid rgba(248,113,113,.35); }
.alert.error strong { color: var(--err); display: block; margin-bottom: 6px; }
.alertbody code { display: inline-block; margin: 4px 0; }
.meter { font-size: .82rem; color: var(--muted); min-height: 1.2em; margin-top: -6px; margin-bottom: 10px; }
.note { font-size: .82rem; color: var(--muted); }
.hint { font-size: .78rem; color: var(--muted); display: block; margin-top: 2px; }
.success-hero { text-align: center; padding: 18px 0 26px; }
.success-hero .big {
  width: 72px; height: 72px; border-radius: 50%;
  background: rgba(52,211,153,.12); color: var(--ok);
  display: flex; align-items: center; justify-content: center;
  font-size: 2.2rem; margin: 0 auto 18px;
}
.foot { text-align: center; margin-top: 34px; font-size: .75rem; color: var(--muted); }
.spin {
  width: 18px; height: 18px; border: 2px solid rgba(255,255,255,.3);
  border-top-color: #fff; border-radius: 50%;
  display: inline-block; animation: spin .8s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
@media (max-width: 640px) {
  .wrap { padding: 28px 14px 40px; }
  .card { padding: 22px; }
  .step .lbl { display: none; }
  .step.active .lbl { display: inline; }
}
CSS;
    }
}
