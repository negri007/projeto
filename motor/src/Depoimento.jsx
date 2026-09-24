import {AbsoluteFill, Img, staticFile, useCurrentFrame, useVideoConfig, interpolate, spring} from 'remotion';
import {loadFont as loadAnton} from '@remotion/google-fonts/Anton';
import {loadFont as loadPoppins} from '@remotion/google-fonts/Poppins';
import {preset, familia} from './presets';

const ANTON = loadAnton().fontFamily;
const POP = loadPoppins().fontFamily;

// DEPOIMENTO — prova social: aspas, estrelas preenchendo, texto do cliente,
// avatar com inicial. Qualquer nicho. Duracao alvo: 168f (5.6s).
export const Depoimento = ({
  nicho = 'comida',
  texto = 'Melhor hamburguer que ja comi na cidade. Chega quentinho e o atendimento e impecavel!',
  cliente = 'Marina Alves',
  estrelas = 5,
  foto = 'burger.jpg',
  marca = 'BURGER HOUSE',
  cta = 'Peca o seu',
  cor = null,
}) => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  const p = preset(nicho);
  const accent = cor || p.accent;
  const fam = familia(p, ANTON);
  const inicial = (cliente || 'C').trim().charAt(0).toUpperCase();

  const card = spring({frame: f, fps, config: {damping: 14, stiffness: 90}});
  const escCard = interpolate(card, [0, 1], [0.85, 1]);
  const aspasO = interpolate(f, [8, 20], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const textoO = interpolate(f, [24, 40], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const cliT = spring({frame: f - 60, fps, config: {damping: 13}});
  const ctaT = interpolate(f, [120, 136], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});

  return (
    <AbsoluteFill style={{background: p.bg}}>
      {/* fundo desfocado do produto */}
      <AbsoluteFill style={{transform: 'scale(1.2)'}}>
        <Img src={staticFile(foto)} style={{width: '100%', height: '100%', objectFit: 'cover', filter: `blur(26px) brightness(.4) ${p.filtro}`}} />
      </AbsoluteFill>
      <AbsoluteFill style={{background: `radial-gradient(circle at 50% 40%, ${accent}18, transparent 62%)`}} />

      {/* cartao de depoimento */}
      <AbsoluteFill style={{alignItems: 'center', justifyContent: 'center'}}>
        <div style={{width: 760, background: 'rgba(255,255,255,.96)', borderRadius: 30, padding: '56px 54px',
          transform: `scale(${escCard})`, opacity: card, boxShadow: '0 40px 100px rgba(0,0,0,.5)'}}>
          <div style={{fontFamily: 'Georgia, serif', fontSize: 130, lineHeight: 0.6, color: accent, opacity: aspasO, height: 70}}>&ldquo;</div>

          {/* estrelas preenchendo uma a uma */}
          <div style={{display: 'flex', gap: 8, margin: '6px 0 22px'}}>
            {[0, 1, 2, 3, 4].map((i) => {
              const on = i < estrelas ? interpolate(f, [30 + i * 6, 40 + i * 6], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'}) : 0;
              return <span key={i} style={{fontSize: 46, color: `rgba(0,0,0,.15)`, position: 'relative'}}>★
                <span style={{position: 'absolute', left: 0, top: 0, color: accent, clipPath: `inset(0 ${100 - on * 100}% 0 0)`}}>★</span>
              </span>;
            })}
          </div>

          <div style={{fontFamily: POP, fontWeight: 500, fontSize: 38, lineHeight: 1.35, color: '#1a1a1a', opacity: textoO}}>{texto}</div>

          <div style={{marginTop: 34, display: 'flex', alignItems: 'center', gap: 18, opacity: interpolate(cliT, [0, 1], [0, 1]), transform: `translateY(${interpolate(cliT, [0, 1], [16, 0])}px)`}}>
            <div style={{width: 72, height: 72, borderRadius: '50%', background: accent, display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: fam, fontWeight: 700, fontSize: 38, color: '#fff'}}>{inicial}</div>
            <div>
              <div style={{fontFamily: POP, fontWeight: 800, fontSize: 30, color: '#111'}}>{cliente}</div>
              <div style={{fontFamily: POP, fontWeight: 600, fontSize: 22, color: accent}}>cliente verificado</div>
            </div>
          </div>
        </div>
      </AbsoluteFill>

      {/* marca + cta embaixo */}
      <div style={{position: 'absolute', bottom: 70, left: 0, right: 0, textAlign: 'center', opacity: ctaT, transform: `translateY(${interpolate(ctaT, [0, 1], [16, 0])}px)`}}>
        <span style={{fontFamily: POP, fontWeight: 700, fontSize: 26, color: '#111', background: accent, padding: '12px 32px', borderRadius: 40}}>{cta}</span>
        <span style={{fontFamily: fam, fontWeight: 700, fontSize: 26, letterSpacing: 3, color: '#fff', marginLeft: 18}}>{marca}</span>
      </div>

      <AbsoluteFill style={{boxShadow: 'inset 0 0 300px 90px rgba(0,0,0,.55)', pointerEvents: 'none'}} />
    </AbsoluteFill>
  );
};
