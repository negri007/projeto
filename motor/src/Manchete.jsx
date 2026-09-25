import {AbsoluteFill, Img, staticFile, useCurrentFrame, useVideoConfig, interpolate, spring, Easing} from 'remotion';
import {loadAnton, loadPoppins} from './fontes';
import {preset, familia} from './presets';

const ANTON = loadAnton().fontFamily;
const POP = loadPoppins().fontFamily;

const LOCK = 42; // frame em que o card TRAVA

// MANCHETE — card gira rapido, desacelera e TRAVA com impacto; depois
// entram as informacoes. Estilo "oferta/plantao". Duracao alvo: 168f (5.6s).
export const Manchete = ({
  nicho = 'comida',
  selo = 'OFERTA',
  chamada = ['SMASH', 'BURGER'],
  preco = 'R$ 32,90',
  infos = ['PAO BRIOCHE', 'CARNE 180G', 'ENTREGA GRATIS'],
  cta = 'Peca pelo WhatsApp',
  marca = 'BURGER HOUSE',
  foto = 'burger.jpg',
  giros = 2.5,
  cor = null,
}) => {
  const f = useCurrentFrame();
  const {fps, durationInFrames: dur} = useVideoConfig();
  const p = preset(nicho);
  const accent = cor || p.accent;
  const fam = familia(p, ANTON);
  const palavras = Array.isArray(chamada) ? chamada : String(chamada).split(' ');

  // GIRO: desacelera ate travar em LOCK. Ease-out cubico = rapido no inicio,
  // lento no fim (a "freada").
  const t = interpolate(f, [0, LOCK], [0, 1], {extrapolateRight: 'clamp', easing: Easing.out(Easing.cubic)});
  const angBase = interpolate(t, [0, 1], [-360 * giros, 0]);
  // micro-overshoot no travamento (a "batida" que assenta).
  const wobble = f < LOCK ? 0 : interpolate(f, [LOCK, LOCK + 10], [8, 0], {extrapolateRight: 'clamp'}) * Math.sin((f - LOCK) * 1.4);
  const ang = angBase + wobble;

  // ESCALA: entra pequeno, cresce; punch no travamento.
  const escIn = interpolate(t, [0, 1], [0.15, 1]);
  const punch = f < LOCK ? 0 : interpolate(spring({frame: f - LOCK, fps, config: {damping: 8, stiffness: 260}}), [0, 1], [0.12, 0]);
  const esc = escIn + punch;

  // desfoque de velocidade enquanto gira.
  const blur = interpolate(f, [0, LOCK - 6, LOCK], [16, 4, 0], {extrapolateRight: 'clamp'});

  // FLASH branco no instante da travada.
  const flash = f < LOCK ? 0 : interpolate(f, [LOCK, LOCK + 8], [0.85, 0], {extrapolateRight: 'clamp'});
  // SHAKE da tela na travada.
  const shakeAmp = f < LOCK ? 0 : interpolate(f, [LOCK, LOCK + 12], [14, 0], {extrapolateRight: 'clamp'});
  const shakeX = shakeAmp * Math.sin((f - LOCK) * 2.1);
  const shakeY = shakeAmp * Math.cos((f - LOCK) * 1.7);

  // linhas de velocidade (some ao travar).
  const speedOp = interpolate(f, [0, LOCK - 8, LOCK], [0.5, 0.5, 0], {extrapolateRight: 'clamp'});

  // reveals pos-travada (relativos a LOCK).
  const rf = f - LOCK;
  const seloT = spring({frame: rf - 4, fps, config: {damping: 9, stiffness: 220}});
  const precoT = spring({frame: rf - 16, fps, config: {damping: 11, stiffness: 150}});
  const ctaT = interpolate(rf, [40, 54], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});

  const CARD_W = 520, CARD_H = 560;

  return (
    <AbsoluteFill style={{background: p.bg}}>
      {/* fundo desfocado do proprio produto */}
      <AbsoluteFill style={{transform: 'scale(1.2)'}}>
        <Img src={staticFile(foto)} style={{width: '100%', height: '100%', objectFit: 'cover', filter: `blur(24px) brightness(.4) ${p.filtro}`}} />
      </AbsoluteFill>
      <AbsoluteFill style={{background: `radial-gradient(circle at 50% 45%, ${accent}22, transparent 60%)`}} />

      {/* linhas de velocidade radiais durante o giro */}
      <AbsoluteFill style={{alignItems: 'center', justifyContent: 'center', opacity: speedOp, pointerEvents: 'none'}}>
        <div style={{width: 1600, height: 1600, transform: `rotate(${ang * 0.5}deg)`,
          background: `repeating-conic-gradient(from 0deg, transparent 0deg 8deg, ${accent}22 8deg 9deg)`,
          borderRadius: '50%', maskImage: 'radial-gradient(circle, transparent 34%, #000 55%)',
          WebkitMaskImage: 'radial-gradient(circle, transparent 34%, #000 55%)'}} />
      </AbsoluteFill>

      {/* CONTEUDO com shake */}
      <AbsoluteFill style={{transform: `translate(${shakeX}px, ${shakeY}px)`, alignItems: 'center', justifyContent: 'center'}}>

        {/* CARD que gira e trava */}
        <div style={{width: CARD_W, height: CARD_H, borderRadius: 28, overflow: 'hidden',
          transform: `translateY(-150px) rotate(${ang}deg) scale(${esc})`, filter: `blur(${blur}px)`,
          boxShadow: `0 30px 90px rgba(0,0,0,.6), 0 0 0 4px ${accent}`, position: 'relative'}}>
          <Img src={staticFile(foto)} style={{width: '100%', height: '100%', objectFit: 'cover', filter: p.filtro}} />
          <div style={{position: 'absolute', inset: 0, background: 'linear-gradient(to top, rgba(0,0,0,.82), transparent 50%)'}} />
          <div style={{position: 'absolute', bottom: 28, left: 28, right: 28}}>
            {palavras.map((w, i) => (
              <div key={i} style={{fontFamily: fam, fontWeight: 700, fontSize: 78,
                color: i === palavras.length - 1 ? accent : '#fff', lineHeight: 0.92,
                textShadow: '0 6px 24px rgba(0,0,0,.6)'}}>{w}</div>
            ))}
          </div>
        </div>

        {/* SELO carimbado (aparece na travada) */}
        <div style={{position: 'absolute', top: 90, right: 90, transform: `rotate(-12deg) scale(${interpolate(seloT, [0, 1], [1.8, 1])})`, opacity: seloT}}>
          <div style={{fontFamily: fam, fontWeight: 700, fontSize: 52, color: '#fff', background: accent,
            padding: '10px 26px', borderRadius: 10, letterSpacing: 2, boxShadow: '0 10px 30px rgba(0,0,0,.5)'}}>{selo}</div>
        </div>

        {/* INFO abaixo do card */}
        <div style={{position: 'absolute', bottom: 70, left: 0, right: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 16}}>
          <div style={{display: 'inline-flex', alignItems: 'baseline', gap: 12,
            transform: `scale(${interpolate(precoT, [0, 1], [0.6, 1])})`, opacity: precoT}}>
            <span style={{fontFamily: POP, fontWeight: 600, fontSize: 22, color: p.creme}}>a partir de</span>
            <span style={{fontFamily: fam, fontWeight: 700, fontSize: 76, color: accent, lineHeight: 1, textShadow: `0 0 40px ${accent}66`}}>{preco}</span>
          </div>
          <div style={{display: 'flex', gap: 14, flexWrap: 'wrap', justifyContent: 'center', padding: '0 40px'}}>
            {(infos || []).map((it, i) => {
              const a = interpolate(rf, [22 + i * 8, 36 + i * 8], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
              return <div key={i} style={{fontFamily: POP, fontWeight: 700, fontSize: 22, color: '#111', background: p.creme,
                padding: '9px 18px', borderRadius: 26, opacity: a, transform: `translateY(${interpolate(a, [0, 1], [18, 0])}px)`}}>{it}</div>;
            })}
          </div>
          <div style={{fontFamily: POP, fontWeight: 700, fontSize: 26, color: '#fff', background: accent,
            padding: '12px 32px', borderRadius: 40, opacity: ctaT, transform: `translateY(${interpolate(ctaT, [0, 1], [16, 0])}px)`}}>{cta}</div>
        </div>
      </AbsoluteFill>

      {/* FLASH da travada */}
      <AbsoluteFill style={{background: '#fff', opacity: flash, pointerEvents: 'none'}} />
      {/* vinheta */}
      <AbsoluteFill style={{boxShadow: 'inset 0 0 300px 80px rgba(0,0,0,.55)', pointerEvents: 'none'}} />
    </AbsoluteFill>
  );
};
