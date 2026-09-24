import {AbsoluteFill, Img, staticFile, useCurrentFrame, useVideoConfig, interpolate, spring} from 'remotion';
import {loadFont as loadAnton} from '@remotion/google-fonts/Anton';
import {loadFont as loadPoppins} from '@remotion/google-fonts/Poppins';
import {preset, familia} from './presets';
import {Foto} from './Foto';

const ANTON = loadAnton().fontFamily;
const POP = loadPoppins().fontFamily;

const pad = (n) => String(Math.max(0, Math.floor(n))).padStart(2, '0');

// COUNTDOWN — urgencia: relogio regressivo pulsando. Duracao alvo: 168f.
export const Countdown = ({
  nicho = 'fitness',
  selo = 'SO HOJE',
  chamada = ['ULTIMAS', 'VAGAS'],
  inicio = {h: 5, m: 42, s: 18},
  preco = 'R$ 99/mes',
  cta = 'Garanta a sua',
  marca = 'FORCA GYM',
  foto = 'fitness.jpg',
  cor = null,
  ajuste = 'preencher',
  foco = null,
}) => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  const p = preset(nicho);
  const accent = cor || p.accent;
  const fam = familia(p, ANTON);
  const palavras = Array.isArray(chamada) ? chamada : String(chamada).split(' ');

  // regride ~1s por frame pra dar sensacao de tempo correndo.
  const totalIni = inicio.h * 3600 + inicio.m * 60 + inicio.s;
  const rest = Math.max(0, totalIni - Math.floor(f * 1.0));
  const hh = pad(rest / 3600), mm = pad((rest % 3600) / 60), ss = pad(rest % 60);
  const pulse = 1 + 0.06 * Math.sin(f * 0.5);

  const seloT = spring({frame: f - 6, fps, config: {damping: 9, stiffness: 200}});
  const titO = interpolate(f, [16, 30], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const boxO = interpolate(f, [30, 46], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const precoT = spring({frame: f - 100, fps, config: {damping: 11, stiffness: 150}});
  const ctaT = interpolate(f, [130, 146], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});

  const Box = ({v, lbl}) => (
    <div style={{textAlign: 'center'}}>
      <div style={{width: 160, height: 170, borderRadius: 20, background: 'rgba(0,0,0,.5)', border: `2px solid ${accent}`,
        display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: fam, fontWeight: 700, fontSize: 100, color: '#fff',
        boxShadow: `0 0 40px ${accent}44`}}>{v}</div>
      <div style={{fontFamily: POP, fontWeight: 700, fontSize: 22, letterSpacing: 3, color: p.creme, marginTop: 10}}>{lbl}</div>
    </div>
  );

  return (
    <AbsoluteFill style={{background: p.bg}}>
      <AbsoluteFill style={{transform: `scale(${interpolate(f, [0, 168], [1.06, 1.14])})`}}>
        <Foto src={foto} filtro={`brightness(.5) ${p.filtro}`} ajuste={ajuste} foco={foco} />
      </AbsoluteFill>
      <AbsoluteFill style={{background: 'linear-gradient(to bottom, rgba(0,0,0,.5), rgba(0,0,0,.35) 40%, rgba(0,0,0,.7))'}} />

      {/* selo pulsando */}
      <div style={{position: 'absolute', top: 70, left: 0, right: 0, textAlign: 'center', transform: `scale(${interpolate(seloT, [0, 1], [1.6, 1]) * pulse})`, opacity: seloT}}>
        <span style={{fontFamily: fam, fontWeight: 700, fontSize: 58, color: '#fff', background: accent, padding: '8px 34px', borderRadius: 10, letterSpacing: 3}}>{selo}</span>
      </div>

      <div style={{position: 'absolute', top: 186, left: 0, right: 0, textAlign: 'center', opacity: titO}}>
        {palavras.map((w, i) => (
          <span key={i} style={{fontFamily: fam, fontWeight: 700, fontSize: 78, color: i === palavras.length - 1 ? accent : '#fff', margin: '0 12px'}}>{w}</span>
        ))}
      </div>

      {/* relogio */}
      <AbsoluteFill style={{alignItems: 'center', justifyContent: 'center', opacity: boxO}}>
        <div style={{display: 'flex', alignItems: 'center', gap: 18, transform: `scale(${pulse})`}}>
          <Box v={hh} lbl="HORAS" />
          <div style={{fontFamily: fam, fontSize: 90, color: accent, marginBottom: 30}}>:</div>
          <Box v={mm} lbl="MIN" />
          <div style={{fontFamily: fam, fontSize: 90, color: accent, marginBottom: 30}}>:</div>
          <Box v={ss} lbl="SEG" />
        </div>
      </AbsoluteFill>

      {/* preco + cta */}
      <div style={{position: 'absolute', bottom: 74, left: 0, right: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 14}}>
        <div style={{display: 'inline-flex', alignItems: 'baseline', gap: 12, transform: `scale(${interpolate(precoT, [0, 1], [0.6, 1])})`, opacity: precoT}}>
          <span style={{fontFamily: POP, fontWeight: 600, fontSize: 22, color: p.creme}}>a partir de</span>
          <span style={{fontFamily: fam, fontWeight: 700, fontSize: 70, color: accent, lineHeight: 1, textShadow: `0 0 40px ${accent}66`}}>{preco}</span>
        </div>
        <div style={{opacity: ctaT, transform: `translateY(${interpolate(ctaT, [0, 1], [16, 0])}px)`}}>
          <span style={{fontFamily: POP, fontWeight: 700, fontSize: 26, color: '#111', background: accent, padding: '12px 32px', borderRadius: 40}}>{cta}</span>
          <span style={{fontFamily: fam, fontWeight: 700, fontSize: 24, letterSpacing: 3, color: '#fff', marginLeft: 18}}>{marca}</span>
        </div>
      </div>

      <AbsoluteFill style={{boxShadow: 'inset 0 0 280px 80px rgba(0,0,0,.5)', pointerEvents: 'none'}} />
    </AbsoluteFill>
  );
};
