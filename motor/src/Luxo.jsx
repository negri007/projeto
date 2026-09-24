import {AbsoluteFill, Img, staticFile, useCurrentFrame, useVideoConfig, interpolate, spring} from 'remotion';
import {loadFont as loadPoppins} from '@remotion/google-fonts/Poppins';
import {preset, familia} from './presets';

const POP = loadPoppins().fontFamily;
const SERIF = 'Georgia, "Times New Roman", serif';

// LUXO — produto centralizado, brilho dourado varrendo, glints piscando.
// Estilo joia / alto padrao. Duracao alvo: 168f (5.6s).
export const Luxo = ({
  nicho = 'joia',
  chamada = 'Coleção Aurora',
  sub = 'ouro 18k • diamantes naturais',
  preco = 'R$ 1.290',
  cta = 'Agende uma visita',
  marca = 'AURUM',
  foto = 'joia.jpg',
  cor = null,
}) => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  const p = preset(nicho);
  const accent = cor || p.accent;
  const fam = familia(p, null) === 'serif' ? SERIF : SERIF; // luxo sempre serif

  const entra = spring({frame: f, fps, config: {damping: 16, stiffness: 70}});
  const esc = interpolate(entra, [0, 1], [0.8, 1]) * interpolate(f, [0, 168], [1, 1.06]);
  const rot = interpolate(f, [0, 168], [-3, 3]);

  // sweep dourado diagonal, passa duas vezes.
  const sweep1 = interpolate(f, [30, 60], [-1.2, 1.6], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const sweep2 = interpolate(f, [96, 126], [-1.2, 1.6], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});

  const titO = interpolate(f, [40, 60], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const lineW = interpolate(f, [52, 78], [0, 260], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const precoT = spring({frame: f - 96, fps, config: {damping: 13, stiffness: 120}});
  const ctaT = interpolate(f, [136, 150], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});

  // glints (brilhos que piscam) em posicoes fixas.
  const glints = [[300, 360], [740, 300], [560, 520], [420, 240]];

  return (
    <AbsoluteFill style={{background: 'radial-gradient(circle at 50% 40%, #1c1c1c, #000 75%)'}}>
      {/* marca fantasma ao fundo */}
      <AbsoluteFill style={{alignItems: 'center', justifyContent: 'center'}}>
        <div style={{fontFamily: fam, fontSize: 340, color: '#ffffff06', letterSpacing: 20, fontWeight: 700}}>{marca}</div>
      </AbsoluteFill>

      {/* produto em moldura */}
      <AbsoluteFill style={{alignItems: 'center', justifyContent: 'center', top: -70}}>
        <div style={{width: 560, height: 560, borderRadius: '50%', overflow: 'hidden', position: 'relative',
          transform: `scale(${esc}) rotate(${rot}deg)`, boxShadow: `0 0 0 2px ${accent}, 0 0 60px ${accent}44, 0 30px 80px rgba(0,0,0,.7)`}}>
          <Img src={staticFile(foto)} style={{width: '100%', height: '100%', objectFit: 'cover', filter: p.filtro}} />
          {/* sweeps dourados */}
          {[sweep1, sweep2].map((s, i) => (
            <div key={i} style={{position: 'absolute', top: 0, bottom: 0, left: `${s * 100}%`, width: '45%',
              background: `linear-gradient(100deg, transparent, ${accent}cc, transparent)`, transform: 'skewX(-18deg)', mixBlendMode: 'screen'}} />
          ))}
        </div>
      </AbsoluteFill>

      {/* glints */}
      {glints.map((g, i) => {
        const t = (f + i * 24) % 96;
        const a = interpolate(t, [0, 8, 16], [0, 1, 0], {extrapolateRight: 'clamp'});
        return <div key={i} style={{position: 'absolute', left: g[0], top: g[1], width: 26, height: 26, opacity: a,
          background: `radial-gradient(circle, #fff, ${accent} 40%, transparent 70%)`, transform: `rotate(45deg) scale(${a})`}} />;
      })}

      {/* textos embaixo */}
      <div style={{position: 'absolute', bottom: 96, left: 0, right: 0, textAlign: 'center'}}>
        <div style={{fontFamily: fam, fontWeight: 700, fontSize: 68, color: '#fff', opacity: titO, letterSpacing: 1}}>{chamada}</div>
        <div style={{width: lineW, height: 2, background: accent, margin: '16px auto'}} />
        <div style={{fontFamily: POP, fontWeight: 400, fontSize: 24, color: '#cbb98f', opacity: titO, letterSpacing: 3}}>{sub}</div>
        <div style={{marginTop: 22, display: 'inline-flex', alignItems: 'baseline', gap: 12, transform: `scale(${interpolate(precoT, [0, 1], [0.7, 1])})`, opacity: precoT}}>
          <span style={{fontFamily: POP, fontWeight: 400, fontSize: 22, color: '#cbb98f'}}>a partir de</span>
          <span style={{fontFamily: fam, fontWeight: 700, fontSize: 66, color: accent}}>{preco}</span>
        </div>
        <div style={{marginTop: 20, opacity: ctaT, transform: `translateY(${interpolate(ctaT, [0, 1], [14, 0])}px)`}}>
          <span style={{fontFamily: POP, fontWeight: 600, fontSize: 22, color: '#111', background: accent, padding: '11px 30px', borderRadius: 2, letterSpacing: 2}}>{cta}</span>
        </div>
      </div>

      <AbsoluteFill style={{boxShadow: 'inset 0 0 320px 100px rgba(0,0,0,.7)', pointerEvents: 'none'}} />
    </AbsoluteFill>
  );
};
