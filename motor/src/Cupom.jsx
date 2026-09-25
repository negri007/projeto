import {AbsoluteFill, Img, staticFile, useCurrentFrame, useVideoConfig, interpolate, spring} from 'remotion';
import {loadAnton, loadPoppins} from './fontes';
import {preset, familia} from './presets';

const ANTON = loadAnton().fontFamily;
const POP = loadPoppins().fontFamily;

// CUPOM — ticket de desconto com codigo destacado. Duracao alvo: 156f.
export const Cupom = ({
  nicho = 'comida',
  desconto = '20% OFF',
  codigo = 'ECHO20',
  validade = 'valido ate domingo',
  chamada = 'CUPOM DE DESCONTO',
  cta = 'Use no checkout',
  marca = 'BURGER HOUSE',
  foto = 'burger.jpg',
  cor = null,
}) => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  const p = preset(nicho);
  const accent = cor || p.accent;
  const fam = familia(p, ANTON);

  const ticket = spring({frame: f - 10, fps, config: {damping: 11, stiffness: 120}});
  const descO = spring({frame: f - 30, fps, config: {damping: 10, stiffness: 150}});
  const codO = interpolate(f, [50, 66], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const ctaT = interpolate(f, [110, 126], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const shine = interpolate(f, [66, 96], [-1, 2], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});

  return (
    <AbsoluteFill style={{background: p.bg}}>
      <AbsoluteFill style={{transform: 'scale(1.2)'}}>
        <Img src={staticFile(foto)} style={{width: '100%', height: '100%', objectFit: 'cover', filter: `blur(28px) brightness(.3) ${p.filtro}`}} />
      </AbsoluteFill>
      <AbsoluteFill style={{background: `radial-gradient(circle at 50% 42%, ${accent}22, transparent 62%)`}} />

      <div style={{position: 'absolute', top: 90, left: 0, right: 0, textAlign: 'center', fontFamily: POP, fontWeight: 800, fontSize: 30, letterSpacing: 6, color: accent, opacity: interpolate(f, [4, 18], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'})}}>{chamada}</div>

      {/* ticket */}
      <AbsoluteFill style={{alignItems: 'center', justifyContent: 'center'}}>
        <div style={{width: 800, background: '#fff', borderRadius: 26, padding: '50px 40px', position: 'relative', overflow: 'hidden',
          transform: `scale(${interpolate(ticket, [0, 1], [0.8, 1])}) rotate(${interpolate(ticket, [0, 1], [-4, 0])}deg)`, opacity: ticket,
          boxShadow: '0 40px 100px rgba(0,0,0,.5)'}}>
          {/* recortes laterais do ticket */}
          <div style={{position: 'absolute', left: -24, top: '50%', width: 48, height: 48, borderRadius: '50%', background: p.bg, transform: 'translateY(-50%)'}} />
          <div style={{position: 'absolute', right: -24, top: '50%', width: 48, height: 48, borderRadius: '50%', background: p.bg, transform: 'translateY(-50%)'}} />

          <div style={{textAlign: 'center', fontFamily: fam, fontWeight: 700, fontSize: 150, color: accent, lineHeight: 0.9,
            transform: `scale(${interpolate(descO, [0, 1], [0.6, 1])})`, opacity: descO}}>{desconto}</div>

          <div style={{margin: '30px auto 0', width: 'fit-content', border: `3px dashed ${accent}`, borderRadius: 14, padding: '14px 40px', opacity: codO}}>
            <span style={{fontFamily: POP, fontWeight: 600, fontSize: 22, color: '#666', marginRight: 12}}>código</span>
            <span style={{fontFamily: fam, fontWeight: 700, fontSize: 56, color: '#111', letterSpacing: 4}}>{codigo}</span>
          </div>
          <div style={{textAlign: 'center', marginTop: 18, fontFamily: POP, fontWeight: 600, fontSize: 24, color: '#888', opacity: codO}}>{validade}</div>

          {/* brilho passando */}
          <div style={{position: 'absolute', top: 0, bottom: 0, left: `${shine * 100}%`, width: '35%',
            background: 'linear-gradient(100deg, transparent, rgba(255,255,255,.6), transparent)', transform: 'skewX(-18deg)'}} />
        </div>
      </AbsoluteFill>

      <div style={{position: 'absolute', bottom: 70, left: 0, right: 0, textAlign: 'center', opacity: ctaT, transform: `translateY(${interpolate(ctaT, [0, 1], [16, 0])}px)`}}>
        <span style={{fontFamily: POP, fontWeight: 700, fontSize: 26, color: '#111', background: accent, padding: '12px 32px', borderRadius: 40}}>{cta}</span>
        <span style={{fontFamily: fam, fontWeight: 700, fontSize: 26, letterSpacing: 3, color: '#fff', marginLeft: 18}}>{marca}</span>
      </div>

      <AbsoluteFill style={{boxShadow: 'inset 0 0 300px 90px rgba(0,0,0,.55)', pointerEvents: 'none'}} />
    </AbsoluteFill>
  );
};
