// ForgePanel — root app: routing, shortcuts, tweaks
const TWEAK_DEFAULTS = /*EDITMODE-BEGIN*/{
  "accent": "#1f9d6b",
  "theme": "Svijetla",
  "density": "regular",
  "anim": true
}/*EDITMODE-END*/;

const ACCENT_HUES = { '#1f9d6b': 163, '#3b82f6': 250, '#8b5cf6': 300, '#d97706': 75 };

function applyTweaks(t) {
  const root = document.documentElement;
  const hue = ACCENT_HUES[t.accent] || 163;
  root.style.setProperty('--accent', `oklch(0.62 0.125 ${hue})`);
  root.style.setProperty('--accent-deep', `oklch(0.49 0.11 ${hue})`);
  root.style.setProperty('--accent-glow', `oklch(0.62 0.125 ${hue} / 0.16)`);
  root.style.setProperty('--ok', `oklch(0.62 0.125 ${hue})`);
  const dark = t.theme === 'Tamna';
  root.setAttribute('data-theme', dark ? 'dark' : 'light');
  root.style.setProperty('--accent-soft', dark ? `oklch(0.28 0.05 ${hue})` : `oklch(0.95 0.028 ${hue})`);
  root.style.setProperty('--ok-soft', dark ? `oklch(0.28 0.05 ${hue})` : `oklch(0.95 0.028 ${hue})`);
  root.setAttribute('data-density', t.density);
  root.setAttribute('data-anim', t.anim ? 'on' : 'off');
}

const VIEW_TITLES = { dashboard: 'Pregled', sites: 'Siteovi', files: 'Datoteke', monitoring: 'Monitoring', settings: 'SSL · DNS · Sigurnost' };

function App() {
  const [t, setTweak] = useTweaks(TWEAK_DEFAULTS);
  const [view, setView] = useState(() => localStorage.getItem('fp-view') || 'dashboard');
  const [paletteOpen, setPaletteOpen] = useState(false);
  const [aiOpen, setAiOpen] = useState(false);
  const gRef = useRef(false);

  useEffect(() => applyTweaks(t), [t]);
  useEffect(() => { localStorage.setItem('fp-view', view); }, [view]);

  useEffect(() => {
    const onKey = (e) => {
      const inInput = ['INPUT', 'TEXTAREA'].includes(document.activeElement && document.activeElement.tagName);
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); setPaletteOpen(o => !o); return; }
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'j') { e.preventDefault(); setAiOpen(o => !o); return; }
      if (inInput) return;
      if (e.key.toLowerCase() === 'g') { gRef.current = true; setTimeout(() => gRef.current = false, 900); return; }
      if (gRef.current) {
        const map = { d: 'dashboard', s: 'sites', f: 'files', m: 'monitoring', p: 'settings' };
        const v = map[e.key.toLowerCase()];
        if (v) { setView(v); gRef.current = false; }
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  const Screen = {
    dashboard: window.DashboardScreen, sites: window.SitesScreen, files: window.FilesScreen,
    monitoring: window.MonitoringScreen, settings: window.SettingsScreen,
  }[view];

  const isFiles = view === 'files';

  return (
    <div style={{ display: 'flex', height: '100vh', overflow: 'hidden' }}>
      <Rail view={view} setView={setView}/>
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column', minWidth: 0 }}>
        <TopBar crumbs={[VIEW_TITLES[view]]} onPalette={() => setPaletteOpen(true)} onAI={() => setAiOpen(o => !o)} aiOpen={aiOpen}/>
        <div style={{ flex: 1, display: 'flex', minHeight: 0 }}>
          <main data-screen-label={VIEW_TITLES[view]} key={view}
            style={{ flex: 1, minWidth: 0, minHeight: 0, overflowY: isFiles ? 'hidden' : 'auto', padding: 'var(--gap)',
              display: isFiles ? 'flex' : 'block', flexDirection: 'column' }}>
            {Screen ? (isFiles ? <div style={{ flex: 1, minHeight: 0 }}><Screen/></div> : <Screen/>) : null}
          </main>
          <AIDrawer open={aiOpen} onClose={() => setAiOpen(false)}/>
        </div>
      </div>

      <CommandPalette open={paletteOpen} onClose={() => setPaletteOpen(false)} setView={setView} openAI={() => setAiOpen(true)}/>

      <TweaksPanel>
        <TweakSection label="Izgled"/>
        <TweakColor label="Akcent" value={t.accent} options={['#1f9d6b', '#3b82f6', '#8b5cf6', '#d97706']}
          onChange={v => setTweak('accent', v)}/>
        <TweakRadio label="Tema" value={t.theme} options={['Svijetla', 'Tamna']} onChange={v => setTweak('theme', v)}/>
        <TweakRadio label="Gustoća" value={t.density} options={['regular', 'compact']} onChange={v => setTweak('density', v)}/>
        <TweakSection label="Ponašanje"/>
        <TweakToggle label="Animacije" value={t.anim} onChange={v => setTweak('anim', v)}/>
      </TweaksPanel>
    </div>
  );
}

ReactDOM.createRoot(document.getElementById('root')).render(<App/>);
