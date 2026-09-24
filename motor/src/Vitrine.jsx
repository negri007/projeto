import {AbsoluteFill, Img, staticFile, useCurrentFrame, useVideoConfig, interpolate, spring, Easing} from 'remotion';
import {loadFont as loadAnton} from '@remotion/google-fonts/Anton';
import {loadFont as loadPoppins} from '@remotion/google-fonts/Poppins';
import {preset, familia} from './presets';

const ANTON = loadAnton().fontFamily;
const POP = loadPoppins().fontFamily;

const FLIP = 66; // frame em que comeca a virar

// VITRINE — card mostra a foto, VIRA em 3D e revela as specs no verso.
// Duracao alvo: 156f (5.2s).
export const Vitrine = ({
  nicho = 'tech',
  produto = 'FONE PRO',
  specs = ['40H DE BATERIA', 'CANCELAMENTO DE RUIDO', 'BLUETOOTH 5.3'],
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

  const entra = spring({frame: f, fps, config: {damping: 13, stiffness: 90}});
  const escIn = interpolate(entra, [0, 1], [0.7, 1]);

  // vira 0 -> 180 graus.
  const flip = interpolate(f, [FLIP, FLIP + 26], [0, 180], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: Easing.inOut(Easing.cubic)});
  const mostrouVerso = f > FLIP + 13;

  // brilho que passa no momento da virada.
  const shine = interpolate(f, [FLIP + 6, FLIP + 22], [-1, 2], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const rf = f - (FLIP + 20);
  const ctaT = interpolate(rf, [30, 44], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});

  const W = 560, H = 680;
  const face = {position: 'absolute', inset: 0, borderRadius: 30, overflow: 'hidden',
    backfaceVisibility: 'hidden', WebkitBackfaceVisibility: 'hidden', boxShadow: `0 30px 90px rgba(0,0,0,.6), 0 0 0 4px ${accent}`};

  return (
    <AbsoluteFill style={{background: p.bg}}>
      <AbsoluteFill style={{transform: 'scale(1.2)'}}>
        <Img src={staticFile(foto)} style={{width: '100%', height: '100%', objectFit: 'cover', filter: `blur(26px) brightness(.35) ${p.filtro}`}} />
      </AbsoluteFill>
      <AbsoluteFill style={{background: `radial-gradient(circle at 50% 45%, ${accent}22, transparent 62%)`}} />

      <AbsoluteFill style={{alignItems: 'center', justifyContent: 'center'}}>
        <div style={{width: W, height: H, perspective: 1600}}>
          <div style={{width: '100%', height: '100%', position: 'relative', transformStyle: 'preserve-3d',
            transform: `scale(${escIn}) rotateY(${flip}deg)`}}>

            {/* FRENTE — foto + nome */}
            <div style={face}>
              <Img src={staticFile(foto)} style={{width: '100%', height: '100%', objectFit: 'cover', filter: p.filtro}} />
              <div style={{position: 'absolute', inset: 0, background: 'linear-gradient(to top, rgba(0,0,0,.8), transparent 55%)'}} />
              <div style={{position: 'absolute', bottom: 34, left: 30, right: 30, fontFamily: fam, fontWeight: 700, fontSize: 76, color: '#fff', lineHeight: 0.92}}>{produto}</div>
              {/* brilho da virada */}
              <div style={{position: 'absolute', top: 0, bottom: 0, left: `${shine * 100}%`, width: '40%',
                background: 'linear-gradient(100deg, transparent, rgba(255,255,255,.5), transparent)', transform: 'skewX(-16deg)'}} />
            </div>

            {/* VERSO — specs + preco (pre-espelhado) */}
            <div style={{...face, transform: 'rotateY(180deg)', background: p.bg, border: `4px solid ${accent}`,
              display: 'flex', flexDirection: 'column', justifyContent: 'center', padding: 44}}>
              <div style={{fontFamily: fam, fontWeight: 700, fontSize: 56, color: accent, marginBottom: 22}}>{produto}</div>
              {specs.map((s, i) => {
                const a = mostrouVerso ? interpolate(f, [FLIP + 20 + i * 7, FLIP + 32 + i * 7], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'}) : 0;
                return (
                  <div key={i} style={{display: 'flex', alignItems: 'center', gap: 14, marginBottom: 18, opacity: a, transform: `translateX(${interpolate(a, [0, 1], [-30, 0])}px)`}}>
                    <div style={{width: 12, height: 12, borderRadius: '50%', background: accent}} />
                    <div style={{fontFamily: POP, fontWeight: 700, fontSize: 30, color: '#fff'}}>{s}</div>
                  </div>
                );
              })}
              <div style={{marginTop: 24, display: 'flex', alignItems: 'baseline', gap: 12, opacity: mostrouVerso ? interpolate(f, [FLIP + 40, FLIP + 52], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'}) : 0}}>
                <span style={{fontFamily: POP, fontWeight: 600, fontSize: 24, color: p.creme}}>a partir de</span>
                <span style={{fontFamily: fam, fontWeight: 700, fontSize: 82, color: accent}}>{preco}</span>
              </div>
            </div>
          </div>
        </div>
      </AbsoluteFill>

      {/* CTA + marca fixos embaixo */}
      <div style={{position: 'absolute', bottom: 64, left: 0, right: 0, textAlign: 'center', opacity: ctaT, transform: `translateY(${interpolate(ctaT, [0, 1], [16, 0])}px)`}}>
        <div style={{display: 'inline-block', fontFamily: POP, fontWeight: 700, fontSize: 26, color: '#fff', background: accent, padding: '12px 34px', borderRadius: 40}}>{cta}</div>
        <div style={{marginTop: 14, fontFamily: fam, fontWeight: 700, fontSize: 24, letterSpacing: 3, color: '#fff'}}>{marca}</div>
      </div>

      <AbsoluteFill style={{boxShadow: 'inset 0 0 300px 80px rgba(0,0,0,.55)', pointerEvents: 'none'}} />
    </AbsoluteFill>
  );
};
