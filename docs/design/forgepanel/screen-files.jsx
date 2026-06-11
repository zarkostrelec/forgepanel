// ForgePanel — Files screen: tree + editor + terminal
const FP_CODE = `<?php

namespace App\\Http\\Controllers;

use App\\Services\\PaymentGateway;
use Illuminate\\Http\\Request;

class WebhookController extends Controller
{
    public function __construct(
        private readonly PaymentGateway $gateway,
    ) {}

    /**
     * Stripe webhook — potvrda plaćanja.
     */
    public function handle(Request $request)
    {
        $signature = $request->header('Stripe-Signature');

        if (! $this->gateway->verify($signature, $request->getContent())) {
            abort(403, 'Invalid webhook signature');
        }

        $event = json_decode($request->getContent(), true);

        return match ($event['type']) {
            'payment_intent.succeeded' => $this->confirmOrder($event),
            'charge.refunded'          => $this->refundOrder($event),
            default                    => response()->noContent(),
        };
    }
}`;

function highlightPHP(line) {
  const parts = [];
  let rest = line, key = 0;
  const rules = [
    [/^(\/\/.*|\/\*.*|\*.*|#.*)/, 'var(--ink-3)', true],
    [/^('[^']*'|"[^"]*")/, 'oklch(0.55 0.11 75)'],
    [/^(\$[a-zA-Z_]\w*)/, 'oklch(0.55 0.1 250)'],
    [/^\b(namespace|use|class|extends|public|private|function|return|match|if|abort|readonly|new|true|false|default)\b/, 'oklch(0.5 0.13 300)'],
    [/^\b(\d+)\b/, 'oklch(0.55 0.11 75)'],
    [/^(<\?php)/, 'var(--danger)'],
  ];
  while (rest.length) {
    let matched = false;
    for (const [re, color] of rules) {
      const m = rest.match(re);
      if (m) { parts.push(<span key={key++} style={{ color }}>{m[0]}</span>); rest = rest.slice(m[0].length); matched = true; break; }
    }
    if (!matched) {
      const idx = rest.slice(1).search(/['"$\d\/<]|\b(namespace|use|class|extends|public|private|function|return|match|if|abort|readonly|new)\b/);
      const take = idx === -1 ? rest.length : idx + 1;
      parts.push(<span key={key++}>{rest.slice(0, take)}</span>); rest = rest.slice(take);
    }
  }
  return parts;
}

function TreeNode({ node, depth = 0, active, onPick }) {
  const [open, setOpen] = useState(!!node.open);
  const isDir = node.type === 'dir';
  const isActive = active === node.name;
  const colors = { php: 'oklch(0.55 0.1 290)', json: 'var(--warn)', yml: 'var(--info)', env: 'var(--danger)' };
  return (
    <div>
      <div onClick={() => isDir ? setOpen(o => !o) : onPick(node.name)}
        style={{ display: 'flex', alignItems: 'center', gap: 6, padding: `4px 8px 4px ${10 + depth * 14}px`,
          cursor: 'pointer', fontSize: 'var(--fs-sm)', borderRadius: 6, margin: '0 6px',
          background: isActive ? 'var(--chrome-3)' : 'transparent',
          color: isActive ? '#fff' : 'var(--chrome-ink)' }}
        onMouseEnter={e => { if (!isActive) e.currentTarget.style.background = 'var(--chrome-2)'; }}
        onMouseLeave={e => { if (!isActive) e.currentTarget.style.background = 'transparent'; }}>
        {isDir
          ? <Icon name={open ? 'chevD' : 'chevR'} size={11} style={{ color: 'var(--chrome-ink-2)' }}/>
          : <span style={{ width: 7, height: 7, borderRadius: 2, background: colors[node.type] || 'var(--chrome-ink-2)', flexShrink: 0, marginLeft: 2, marginRight: 2 }}></span>}
        {isDir && <Icon name="folder" size={13} style={{ color: 'var(--chrome-ink-2)' }}/>}
        <span style={{ fontFamily: isDir ? 'var(--font-ui)' : 'var(--font-mono)', fontSize: isDir ? 'var(--fs-sm)' : 'var(--fs-xs)' }}>{node.name}</span>
        {node.mod && <span style={{ width: 6, height: 6, borderRadius: 99, background: 'var(--warn)', marginLeft: 'auto' }}></span>}
        {node.lock && <Icon name="lock" size={10} style={{ marginLeft: 'auto', color: 'var(--chrome-ink-2)' }}/>}
      </div>
      {isDir && open && node.children.map((c, i) => <TreeNode key={i} node={c} depth={depth + 1} active={active} onPick={onPick}/>)}
    </div>
  );
}

function FilesScreen() {
  const D = window.FP_DATA;
  const [activeFile, setActiveFile] = useState('WebhookController.php');
  const [tabs, setTabs] = useState(['WebhookController.php', 'routes/api.php', '.env']);
  const lines = FP_CODE.split('\n');

  return (
    <div style={{ display: 'grid', gridTemplateColumns: '230px 1fr', gap: 0, height: '100%', minHeight: 0,
      border: '1px solid var(--chrome-line)', borderRadius: 'var(--radius)', overflow: 'hidden',
      boxShadow: 'var(--shadow-2)', animation: 'fp-fadeup 0.25s ease' }}>
      {/* tree */}
      <div className="dark-scroll" style={{ background: 'var(--chrome)', overflowY: 'auto', paddingBottom: 12, minHeight: 0 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '11px 14px 9px', position: 'sticky', top: 0, background: 'var(--chrome)' }}>
          <span style={{ fontSize: 'var(--fs-xs)', fontWeight: 650, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'var(--chrome-ink-2)' }}>aurora-shop.hr</span>
          <span style={{ marginLeft: 'auto', display: 'flex', gap: 2 }}>
            <IconBtn dark name="plus" title="Nova datoteka"/><IconBtn dark name="refresh" title="Osvježi"/>
          </span>
        </div>
        {D.files.tree.map((n, i) => <TreeNode key={i} node={n} active={activeFile} onPick={setActiveFile}/>)}
      </div>

      {/* editor column */}
      <div style={{ display: 'flex', flexDirection: 'column', background: 'var(--chrome-2)', minWidth: 0, minHeight: 0 }}>
        {/* tabs */}
        <div style={{ display: 'flex', alignItems: 'stretch', background: 'var(--chrome)', borderBottom: '1px solid var(--chrome-line)', flexShrink: 0 }}>
          {tabs.map(t => {
            const short = t.split('/').pop();
            const act = short === activeFile;
            return (
              <div key={t} onClick={() => setActiveFile(short)} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 14px',
                fontSize: 'var(--fs-xs)', fontFamily: 'var(--font-mono)', cursor: 'pointer', position: 'relative',
                background: act ? 'var(--chrome-2)' : 'transparent',
                color: act ? '#fff' : 'var(--chrome-ink-2)', borderRight: '1px solid var(--chrome-line)' }}>
                {act && <span style={{ position: 'absolute', top: 0, left: 0, right: 0, height: 2, background: 'var(--accent)' }}></span>}
                {short}{short === 'WebhookController.php' && <span style={{ width: 6, height: 6, borderRadius: 99, background: 'var(--warn)' }}></span>}
                <Icon name="x" size={10} style={{ opacity: 0.5 }}/>
              </div>
            );
          })}
          <div style={{ marginLeft: 'auto', display: 'flex', alignItems: 'center', gap: 8, padding: '0 12px',
            fontSize: 'var(--fs-xs)', fontFamily: 'var(--font-mono)', color: 'var(--chrome-ink-2)' }}>
            <Icon name="branch" size={12}/> main · <span style={{ color: 'var(--warn)' }}>1 izmjena</span>
            <Btn small primary icon="check" style={{ marginLeft: 6 }}>Spremi i deployaj</Btn>
          </div>
        </div>

        {/* code */}
        <div className="dark-scroll" style={{ flex: 1, overflow: 'auto', padding: '12px 0', minHeight: 0 }}>
          <pre style={{ margin: 0, fontFamily: 'var(--font-mono)', fontSize: 12.5, lineHeight: 1.65 }}>
            {lines.map((l, i) => (
              <div key={i} style={{ display: 'flex', background: i === 21 ? 'oklch(0.62 0.125 163 / 0.07)' : 'transparent' }}>
                <span style={{ width: 46, textAlign: 'right', paddingRight: 16, color: 'var(--chrome-ink-2)', userSelect: 'none',
                  flexShrink: 0, opacity: 0.6 }}>{i + 1}</span>
                <code style={{ color: 'var(--chrome-ink)', whiteSpace: 'pre' }}>{highlightPHP(l)}{i === 21 && <span style={{
                  display: 'inline-block', width: 2, height: 14, background: 'var(--accent)', verticalAlign: '-2px',
                  animation: 'fp-blink 1.1s step-start infinite' }}></span>}</code>
              </div>
            ))}
          </pre>
        </div>

        {/* terminal */}
        <div style={{ borderTop: '1px solid var(--chrome-line)', background: 'var(--chrome)', flexShrink: 0 }}>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '7px 14px', fontSize: 'var(--fs-xs)',
            color: 'var(--chrome-ink-2)', borderBottom: '1px solid var(--chrome-line)' }}>
            <Icon name="terminal" size={12}/> <span style={{ fontWeight: 650, textTransform: 'uppercase', letterSpacing: '0.05em' }}>Terminal</span>
            <span className="num">ssh aurora@fra1-prod-01</span>
            <span style={{ marginLeft: 'auto', display: 'flex', gap: 4 }}><Kbd>⌃</Kbd><Kbd>`</Kbd></span>
          </div>
          <div style={{ padding: '10px 14px 12px', fontFamily: 'var(--font-mono)', fontSize: 12, lineHeight: 1.7 }}>
            <div style={{ color: 'var(--chrome-ink-2)' }}>$ php artisan test --filter=Webhook</div>
            <div style={{ color: 'var(--chrome-ink)' }}>&nbsp;&nbsp;PASS&nbsp;&nbsp;Tests\Feature\WebhookTest <span style={{ color: 'var(--accent)' }}>✓ 6 passed</span> <span style={{ color: 'var(--chrome-ink-2)' }}>(0.42s)</span></div>
            <div style={{ color: 'var(--chrome-ink)' }}>$ <span style={{ display: 'inline-block', width: 7, height: 13, background: 'var(--accent)', verticalAlign: '-2px', animation: 'fp-blink 1.1s step-start infinite' }}></span></div>
          </div>
        </div>
      </div>
    </div>
  );
}

window.FilesScreen = FilesScreen;
