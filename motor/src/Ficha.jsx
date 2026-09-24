import {AbsoluteFill, Img, staticFile, useCurrentFrame, useVideoConfig, interpolate, spring, Easing} from 'remotion';
import {loadFont as loadAnton} from '@remotion/google-fonts/Anton';
import {loadFont as loadPoppins} from '@remotion/google-fonts/Poppins';
import {preset, familia} from './presets';

const ANTON = loadAnton().fontFamily;
const POP = loadPoppins().fontFamily;

// FICHA — numeros sobem contando de 0 ate o valor (specs). Forte pra
// tech, fitness, automotivo. Duracao alvo: 168f (5.6s).
export const Ficha = ({
  nicho = 'fitness',
  chamada = ['FORCA', 'TOTAL'],
  stats = [{n: 24, unit: 'H', label: 'ABERTO'}, {n: 80, unit: '+', label: 'APARELHOS'}, {n: 12, unit: '', label: 'MODALIDADES'}],
  preco = 'R$ 99/mes',
  cta = 'Matricule-se',
  marca = 'FORCA GYM',
  foto = 'fitness.jpg',
  cor = null,
}) => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  const p = preset(nicho);
  const accent = cor || p.accent;
  const fam = familia(p, ANTON);
  const palavras = Array.isArray(chamada) ? chamada : String(chamada).split(' ');

  const esc = interpolate(f, [0, 168], [1.08, 1.18]);
  const titO = interpolate(f, [4, 20], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const precoT = spring({frame: f - 118, fps, config: {damping: 11, stiffness: 150}});
  const ctaT = interpolate(f, [138, 152], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});

  return (
    <AbsoluteFill style={{background: p.bg}}>
      <AbsoluteFill style={{transform: `scale(${esc})`}}>
        <Img src={staticFile(foto)} style={{width: '100%', height: '100%', objectFit: 'cover', filter: `brightness(.55) ${p.filtro}`}} />
      </AbsoluteFill>
      <AbsoluteFill style={{background: 'linear-gradient(to bottom, rgba(0,0,0,.55), rgba(0,0,0,.35) 40%, rgba(0,0,0,.7))'}} />

      {/* titulo topo */}
      <div style={{position: 'absolute', top: 84, left: 0, right: 0, textAlign: 'center', opacity: titO}}>
        {palavras.map((w, i) => (
          <span key={i} style={{fontFamily: fam, fontWeight: 700, fontSize: 96, color: i === palavras.length - 1 ? accent : '#fff', margin: '0 14px', textShadow: '0 6px 24px rgba(0,0,0,.6)'}}>{w}</span>
        ))}
      </div>

      {/* numeros contando */}
      <AbsoluteFill style={{alignItems: 'center', justifyContent: 'center', gap: 0, flexDirection: 'row'}}>
        <div style={{display: 'flex', gap: 30, alignItems: 'stretch'}}>
          {stats.map((s, i) => {
            const st = 24 + i * 20;
            const prog = interpolate(f, [st, st + 34], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: Easing.out(Easing.cubic)});
            const val = Math.round(prog * s.n);
            const pop = spring({frame: f - st, fps, config: {damping: 12, stiffness: 120}});
            return (
              <div key={i} style={{minWidth: 230, textAlign: 'center', padding: '26px 18px', borderRadius: 22,
                background: 'rgba(0,0,0,.35)', border: `2px solid ${accent}66`,
                transform: `scale(${interpolate(pop, [0, 1], [0.7, 1])})`, opacity: pop}}>
                <div style={{fontFamily: fam, fontWeight: 700, fontSize: 118, color: accent, lineHeight: 0.9, textShadow: `0 0 40px ${accent}66`}}>{val}<span style={{fontSize: 60}}>{s.unit}</span></div>
                <div style={{fontFamily: POP, fontWeight: 700, fontSize: 24, letterSpacing: 2, color: '#fff', marginTop: 8}}>{s.label}</div>
              </div>
            );
          })}
        </div>
      </AbsoluteFill>

      {/* preco + cta embaixo */}
      <div style={{position: 'absolute', bottom: 74, left: 0, right: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 14}}>
        <div style={{display: 'inline-flex', alignItems: 'baseline', gap: 12, transform: `scale(${interpolate(precoT, [0, 1], [0.6, 1])})`, opacity: precoT}}>
          <span style={{fontFamily: POP, fontWeight: 600, fontSize: 22, color: p.creme}}>a partir de</span>
          <span style={{fontFamily: fam, fontWeight: 700, fontSize: 74, color: accent, lineHeight: 1, textShadow: `0 0 40px ${accent}66`}}>{preco}</span>
        </div>
        <div style={{fontFamily: POP, fontWeight: 700, fontSize: 26, color: '#fff', background: accent, padding: '12px 32px', borderRadius: 40, opacity: ctaT, transform: `translateY(${interpolate(ctaT, [0, 1], [16, 0])}px)`}}>{cta}</div>
        <div style={{fontFamily: fam, fontWeight: 700, fontSize: 22, letterSpacing: 3, color: '#fff', opacity: ctaT * 0.85}}>{marca}</div>
      </div>

      <AbsoluteFill style={{boxShadow: 'inset 0 0 280px 80px rgba(0,0,0,.5)', pointerEvents: 'none'}} />
    </AbsoluteFill>
  );
};
