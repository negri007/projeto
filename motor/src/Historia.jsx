import {AbsoluteFill, Img, staticFile, Sequence, useCurrentFrame, useVideoConfig, interpolate, spring} from 'remotion';
import {loadFont as loadAnton} from '@remotion/google-fonts/Anton';
import {loadFont as loadPoppins} from '@remotion/google-fonts/Poppins';
import {preset, familia} from './presets';

const ANTON = loadAnton().fontFamily;
const POP = loadPoppins().fontFamily;

const fadeWrap = (frame, dur) =>
  interpolate(frame, [0, 8, dur - 8, dur], [0, 1, 1, 0], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});

// CENA 1 — abertura: foto + titulo entrando.
const Hero = ({p, accent, fam, chamada, foto}) => {
  const f = useCurrentFrame(); const {fps} = useVideoConfig();
  const o = fadeWrap(f, 78);
  const esc = interpolate(f, [0, 78], [1.06, 1.2]);
  const palavras = Array.isArray(chamada) ? chamada : String(chamada).split(' ');
  return (
    <AbsoluteFill style={{opacity: o}}>
      <AbsoluteFill style={{transform: `scale(${esc})`}}>
        <Img src={staticFile(foto)} style={{width: '100%', height: '100%', objectFit: 'cover', filter: p.filtro}} />
      </AbsoluteFill>
      <AbsoluteFill style={{background: 'linear-gradient(to top, rgba(0,0,0,.68), transparent 55%)'}} />
      <div style={{position: 'absolute', top: 78, left: 64, fontFamily: POP, fontWeight: 800, fontSize: 28, letterSpacing: 6, color: accent, opacity: interpolate(f, [6, 20], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'})}}>{p.badge}</div>
      <div style={{position: 'absolute', bottom: 130, left: 56}}>
        {palavras.map((w, i) => {
          const s = spring({frame: f - (10 + i * 10), fps, config: {damping: 14, stiffness: 120}});
          const x = interpolate(s, [0, 1], [i % 2 ? 520 : -520, 0]);
          return <div key={i} style={{fontFamily: fam, fontWeight: 700, fontSize: 156, color: i === palavras.length - 1 ? accent : '#fff', lineHeight: 0.85, transform: `translateX(${x}px)`, textShadow: '0 8px 30px rgba(0,0,0,.6)'}}>{w}</div>;
        })}
      </div>
    </AbsoluteFill>
  );
};

// CENA 2 — detalhe: foto de perto + tags de destaque.
const Detail = ({p, fam, sub, tags, foto}) => {
  const f = useCurrentFrame();
  const o = fadeWrap(f, 78);
  const esc = interpolate(f, [0, 78], [1.18, 1.04]);
  const rev = interpolate(f, [6, 26], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  return (
    <AbsoluteFill style={{opacity: o}}>
      <AbsoluteFill style={{transform: `scale(${esc})`}}>
        <Img src={staticFile(foto)} style={{width: '100%', height: '100%', objectFit: 'cover', filter: p.filtro}} />
      </AbsoluteFill>
      <AbsoluteFill style={{background: 'linear-gradient(to bottom, rgba(0,0,0,.5), transparent 40%, rgba(0,0,0,.55))'}} />
      <div style={{position: 'absolute', top: 90, left: 0, right: 0, textAlign: 'center', fontFamily: fam, fontWeight: 700, fontSize: 70, color: '#fff', transform: `translateY(${interpolate(rev, [0, 1], [30, 0])}px)`, opacity: rev, textShadow: '0 6px 24px rgba(0,0,0,.6)'}}>{sub}</div>
      <div style={{position: 'absolute', bottom: 120, left: 0, right: 0, display: 'flex', justifyContent: 'center', gap: 20, flexWrap: 'wrap', padding: '0 40px'}}>
        {(tags || []).map((t, i) => {
          const a = interpolate(f, [24 + i * 8, 40 + i * 8], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
          return <div key={i} style={{fontFamily: POP, fontWeight: 700, fontSize: 24, color: '#111', background: p.creme, padding: '10px 20px', borderRadius: 30, opacity: a, transform: `translateY(${interpolate(a, [0, 1], [16, 0])}px)`}}>{t}</div>;
        })}
      </div>
    </AbsoluteFill>
  );
};

// CENA 3 — fecho: preco grande + marca + CTA.
const End = ({p, accent, fam, preco, cta, marca, foto}) => {
  const f = useCurrentFrame(); const {fps} = useVideoConfig();
  const o = fadeWrap(f, 74);
  const esc = interpolate(f, [0, 74], [1.1, 1.18]);
  const precoT = spring({frame: f - 8, fps, config: {damping: 10, stiffness: 150}});
  const logo = spring({frame: f - 30, fps, config: {damping: 12}});
  const ctaT = interpolate(f, [44, 60], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const inicial = (marca || 'M').trim().charAt(0).toUpperCase();
  return (
    <AbsoluteFill style={{opacity: o, background: p.bg}}>
      <AbsoluteFill style={{transform: `scale(${esc})`, opacity: 0.5}}>
        <Img src={staticFile(foto)} style={{width: '100%', height: '100%', objectFit: 'cover', filter: `blur(10px) brightness(.5) ${p.filtro}`}} />
      </AbsoluteFill>
      <AbsoluteFill style={{alignItems: 'center', justifyContent: 'center', flexDirection: 'column'}}>
        <div style={{fontFamily: POP, fontWeight: 700, fontSize: 26, letterSpacing: 4, color: p.creme, marginBottom: 6}}>A PARTIR DE</div>
        <div style={{fontFamily: fam, fontWeight: 700, fontSize: 180, color: accent, lineHeight: 0.9, transform: `scale(${interpolate(precoT, [0, 1], [0.5, 1])})`, opacity: interpolate(precoT, [0, 1], [0, 1]), textShadow: `0 0 50px ${accent}80`}}>{preco}</div>
        <div style={{marginTop: 30, display: 'flex', alignItems: 'center', gap: 16, transform: `scale(${interpolate(logo, [0, 1], [0.7, 1])})`, opacity: interpolate(logo, [0, 1], [0, 1])}}>
          <div style={{width: 64, height: 64, borderRadius: '50%', border: `3px solid ${accent}`, display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: fam, fontWeight: 700, fontSize: 36, color: accent}}>{inicial}</div>
          <div style={{fontFamily: fam, fontWeight: 700, fontSize: 50, color: '#fff', letterSpacing: 2}}>{marca}</div>
        </div>
        <div style={{marginTop: 40, fontFamily: POP, fontWeight: 700, fontSize: 28, color: '#fff', opacity: ctaT, background: accent, padding: '16px 40px', borderRadius: 40}}>{cta}</div>
      </AbsoluteFill>
    </AbsoluteFill>
  );
};

const LightLeak = ({accent}) => {
  const f = useCurrentFrame();
  const x = interpolate(f, [0, 210], [-300, 1400]);
  return (
    <AbsoluteFill style={{mixBlendMode: 'screen', pointerEvents: 'none'}}>
      <div style={{position: 'absolute', top: '-20%', left: x, width: 500, height: '140%', background: `radial-gradient(ellipse at center, ${accent}55, transparent 60%)`, filter: 'blur(30px)', opacity: 0.5 + 0.4 * Math.sin(f / 22)}} />
    </AbsoluteFill>
  );
};

// HISTORIA — anuncio de 3 cenas (plano Pro). Duracao alvo: 210 frames (7s).
export const Historia = ({
  nicho = 'comida',
  chamada = ['SEU', 'PRODUTO'],
  sub = 'QUALIDADE DE VERDADE',
  tags = ['FRESCO', 'ARTESANAL', 'DO DIA'],
  preco = 'R$ 00,00',
  cta = 'Peca pelo WhatsApp',
  marca = 'SUA MARCA',
  fotos = ['hero.jpg', 'detail.jpg'],
  cor = null,
}) => {
  const p = preset(nicho);
  const accent = cor || p.accent;
  const fam = familia(p, ANTON);
  const hero = fotos[0] || 'hero.jpg';
  const det = fotos[1] || fotos[0] || 'detail.jpg';
  const grain = "url(\"data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='140' height='140'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='0.85' numOctaves='2'/></filter><rect width='100%' height='100%' filter='url(%23n)'/></svg>\")";
  return (
    <AbsoluteFill style={{background: '#000'}}>
      <Sequence from={0} durationInFrames={78}><Hero p={p} accent={accent} fam={fam} chamada={chamada} foto={hero} /></Sequence>
      <Sequence from={70} durationInFrames={78}><Detail p={p} fam={fam} sub={sub} tags={tags} foto={det} /></Sequence>
      <Sequence from={138} durationInFrames={74}><End p={p} accent={accent} fam={fam} preco={preco} cta={cta} marca={marca} foto={hero} /></Sequence>
      <LightLeak accent={accent} />
      <AbsoluteFill style={{backgroundImage: grain, opacity: 0.05, mixBlendMode: 'overlay', pointerEvents: 'none'}} />
      <AbsoluteFill style={{boxShadow: 'inset 0 0 320px 90px rgba(0,0,0,.5)', pointerEvents: 'none'}} />
    </AbsoluteFill>
  );
};
