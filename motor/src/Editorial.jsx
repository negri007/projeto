import {AbsoluteFill, Img, staticFile, useCurrentFrame, useVideoConfig, interpolate, spring, Easing} from 'remotion';
import {loadPoppins} from './fontes';
import {preset} from './presets';

const POP = loadPoppins().fontFamily;
const SERIF = 'Georgia, "Times New Roman", serif';

// EDITORIAL — revista de moda: palavra gigante ao fundo, foto revelada por
// mascara (wipe), serifada, linha fina. Duracao alvo: 168f (5.6s).
export const Editorial = ({
  nicho = 'moda',
  fundo = 'ESTILO',
  chamada = 'Nova Coleção',
  sub = 'outono / inverno',
  preco = 'R$ 189',
  cta = 'Compre online',
  marca = 'ATELIE LUNA',
  foto = 'moda.jpg',
  cor = null,
}) => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  const p = preset(nicho);
  const accent = cor || p.accent;

  // wipe da foto (clip-path abrindo de cima).
  const wipe = interpolate(f, [4, 34], [100, 0], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: Easing.inOut(Easing.cubic)});
  const drift = interpolate(f, [0, 168], [1.05, 1.16]);
  const bgX = interpolate(f, [0, 168], [-40, 40]);

  const titO = interpolate(f, [38, 56], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const lineW = interpolate(f, [52, 82], [0, 300], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const precoT = spring({frame: f - 96, fps, config: {damping: 13, stiffness: 120}});
  const ctaT = interpolate(f, [132, 148], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});

  return (
    <AbsoluteFill style={{background: p.bg}}>
      {/* palavra fantasma gigante ao fundo, com leve deriva */}
      <AbsoluteFill style={{alignItems: 'center', justifyContent: 'center', transform: `translateX(${bgX}px)`}}>
        <div style={{fontFamily: SERIF, fontWeight: 700, fontSize: 420, color: '#ffffff08', letterSpacing: 10}}>{fundo}</div>
      </AbsoluteFill>

      {/* foto num retangulo central, revelada por wipe */}
      <AbsoluteFill style={{alignItems: 'center', justifyContent: 'center'}}>
        <div style={{width: 620, height: 800, overflow: 'hidden', clipPath: `inset(${wipe}% 0 0 0)`, boxShadow: '0 30px 80px rgba(0,0,0,.6)'}}>
          <Img src={staticFile(foto)} style={{width: '100%', height: '100%', objectFit: 'cover', transform: `scale(${drift})`, filter: p.filtro}} />
        </div>
      </AbsoluteFill>

      {/* faixa de texto sobre a base */}
      <AbsoluteFill style={{background: 'linear-gradient(to top, rgba(0,0,0,.75), transparent 42%)'}} />

      <div style={{position: 'absolute', bottom: 92, left: 0, right: 0, textAlign: 'center'}}>
        <div style={{fontFamily: POP, fontWeight: 600, fontSize: 22, letterSpacing: 6, color: accent, opacity: titO}}>{sub.toUpperCase()}</div>
        <div style={{fontFamily: SERIF, fontWeight: 700, fontSize: 82, color: '#fff', opacity: titO, marginTop: 6}}>{chamada}</div>
        <div style={{width: lineW, height: 1.5, background: accent, margin: '18px auto'}} />
        <div style={{display: 'inline-flex', alignItems: 'baseline', gap: 12, transform: `scale(${interpolate(precoT, [0, 1], [0.75, 1])})`, opacity: precoT}}>
          <span style={{fontFamily: POP, fontWeight: 400, fontSize: 22, color: '#d8cbb4'}}>a partir de</span>
          <span style={{fontFamily: SERIF, fontWeight: 700, fontSize: 60, color: accent}}>{preco}</span>
        </div>
        <div style={{marginTop: 20, opacity: ctaT, transform: `translateY(${interpolate(ctaT, [0, 1], [14, 0])}px)`}}>
          <span style={{fontFamily: POP, fontWeight: 600, fontSize: 20, letterSpacing: 3, color: '#111', background: accent, padding: '11px 30px', borderRadius: 2}}>{cta}</span>
          <span style={{fontFamily: SERIF, fontWeight: 700, fontSize: 22, letterSpacing: 4, color: '#fff', marginLeft: 18}}>{marca}</span>
        </div>
      </div>

      <AbsoluteFill style={{boxShadow: 'inset 0 0 300px 90px rgba(0,0,0,.5)', pointerEvents: 'none'}} />
    </AbsoluteFill>
  );
};
