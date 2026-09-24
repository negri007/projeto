import {AbsoluteFill, Img, staticFile, useCurrentFrame, useVideoConfig, interpolate, spring} from 'remotion';
import {loadFont as loadAnton} from '@remotion/google-fonts/Anton';
import {loadFont as loadPoppins} from '@remotion/google-fonts/Poppins';
import {preset, familia} from './presets';

const ANTON = loadAnton().fontFamily;
const POP = loadPoppins().fontFamily;

// GLITCH — RGB split + scanlines + rasgos digitais. Estilo tech puro.
// Duracao alvo: 156f (5.2s).
export const Glitch = ({
  nicho = 'tech',
  chamada = ['SOM', 'PURO'],
  specs = ['BLUETOOTH 5.3', '40H BATERIA', 'IPX5'],
  preco = 'R$ 349',
  cta = 'Garanta o seu',
  marca = 'NOVA AUDIO',
  foto = 'tech.jpg',
  cor = null,
}) => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  const p = preset(nicho);
  const accent = cor || p.accent;
  const fam = familia(p, ANTON);
  const palavras = Array.isArray(chamada) ? chamada : String(chamada).split(' ');

  // rajadas de glitch em frames especificos.
  const bursts = [8, 26, 52, 84, 110];
  let amp = 0;
  bursts.forEach((b) => { amp += Math.max(0, 1 - Math.abs(f - b) / 5); });
  amp = Math.min(1, amp);
  const jit = (k) => amp * 26 * Math.sin(f * k);

  const esc = interpolate(f, [0, 156], [1.05, 1.14]);
  const titO = interpolate(f, [10, 24], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const precoT = spring({frame: f - 96, fps, config: {damping: 11, stiffness: 150}});
  const ctaT = interpolate(f, [124, 138], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});

  const imgBase = {width: '100%', height: '100%', objectFit: 'cover'};

  return (
    <AbsoluteFill style={{background: '#03050b'}}>
      <AbsoluteFill style={{transform: `scale(${esc})`}}>
        {/* base normal (escurecida pra o texto ler) */}
        <Img src={staticFile(foto)} style={{...imgBase, filter: `brightness(.42) contrast(1.15) ${p.filtro}`}} />
        {/* fantasmas RGB — SO aparecem nas rajadas (opacity = amp) */}
        <AbsoluteFill style={{transform: `translateX(${jit(12.9)}px)`, mixBlendMode: 'screen', opacity: amp * 0.6}}>
          <Img src={staticFile(foto)} style={{...imgBase, filter: `brightness(.4) sepia(1) saturate(6) hue-rotate(-30deg)`}} />
        </AbsoluteFill>
        <AbsoluteFill style={{transform: `translateX(${-jit(9.7)}px)`, mixBlendMode: 'screen', opacity: amp * 0.6}}>
          <Img src={staticFile(foto)} style={{...imgBase, filter: `brightness(.4) sepia(1) saturate(6) hue-rotate(150deg)`}} />
        </AbsoluteFill>
      </AbsoluteFill>
      {/* scrim escuro uniforme — aterra fotos de fundo claro */}
      <AbsoluteFill style={{background: 'rgba(3,5,11,.5)'}} />

      <AbsoluteFill style={{background: 'linear-gradient(to top, rgba(0,0,0,.75), transparent 55%)'}} />
      {/* scanlines */}
      <AbsoluteFill style={{background: 'repeating-linear-gradient(rgba(255,255,255,.05) 0 1px, transparent 1px 4px)', pointerEvents: 'none', opacity: 0.6}} />
      {/* barra de rasgo digital */}
      {amp > 0.3 && (
        <div style={{position: 'absolute', top: `${(f * 37) % 100}%`, left: 0, right: 0, height: 14,
          background: `${accent}55`, mixBlendMode: 'screen', transform: `translateX(${jit(20)}px)`}} />
      )}

      {/* titulo com fatia glitch */}
      <div style={{position: 'absolute', top: 90, left: 60, opacity: titO}}>
        {palavras.map((w, i) => (
          <div key={i} style={{position: 'relative', fontFamily: fam, fontWeight: 700, fontSize: 130,
            color: '#fff', lineHeight: 0.9, textShadow: `0 0 24px ${accent}`}}>
            <span style={{position: 'absolute', left: jit(15), top: 0, color: accent, opacity: amp, clipPath: 'inset(0 0 55% 0)'}}>{w}</span>
            {w}
          </div>
        ))}
      </div>

      {/* specs em linha mono */}
      <div style={{position: 'absolute', bottom: 250, left: 62, display: 'flex', gap: 14, flexWrap: 'wrap'}}>
        {specs.map((s, i) => {
          const a = interpolate(f, [40 + i * 8, 54 + i * 8], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
          return <span key={i} style={{fontFamily: POP, fontWeight: 700, fontSize: 22, color: accent, border: `1px solid ${accent}`, padding: '6px 14px', borderRadius: 4, opacity: a, letterSpacing: 1}}>{s}</span>;
        })}
      </div>

      {/* preco + cta */}
      <div style={{position: 'absolute', bottom: 92, left: 56, display: 'flex', flexDirection: 'column', gap: 14}}>
        <div style={{display: 'inline-flex', alignItems: 'baseline', gap: 12, transform: `scale(${interpolate(precoT, [0, 1], [0.6, 1])})`, opacity: precoT}}>
          <span style={{fontFamily: POP, fontWeight: 600, fontSize: 22, color: p.creme}}>a partir de</span>
          <span style={{fontFamily: fam, fontWeight: 700, fontSize: 74, color: accent, lineHeight: 1, textShadow: `0 0 40px ${accent}`}}>{preco}</span>
        </div>
        <div style={{display: 'flex', alignItems: 'center', gap: 16, opacity: ctaT}}>
          <span style={{fontFamily: POP, fontWeight: 700, fontSize: 26, color: '#03050b', background: accent, padding: '12px 30px', borderRadius: 4}}>{cta}</span>
          <span style={{fontFamily: fam, fontWeight: 700, fontSize: 24, letterSpacing: 3, color: '#fff'}}>{marca}</span>
        </div>
      </div>

      <AbsoluteFill style={{boxShadow: 'inset 0 0 280px 80px rgba(0,0,0,.55)', pointerEvents: 'none'}} />
    </AbsoluteFill>
  );
};
