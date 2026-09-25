import {AbsoluteFill, Img, staticFile, useCurrentFrame, useVideoConfig, interpolate, spring, Easing} from 'remotion';
import {loadAnton, loadPoppins} from './fontes';
import {preset, familia} from './presets';

const ANTON = loadAnton().fontFamily;
const POP = loadPoppins().fontFamily;

// ANTES/DEPOIS — divisoria desliza revelando o resultado. Forte pra
// estetica, beleza, reforma, odonto. Duracao alvo: 168f (5.6s).
// fotoAntes/fotoDepois podem ser a mesma imagem (o demo diferencia por
// filtro); em producao, duas fotos reais da loja.
export const AntesDepois = ({
  nicho = 'beleza',
  chamada = 'RESULTADO REAL',
  fotoAntes = 'spa.jpg',
  fotoDepois = 'spa.jpg',
  preco = 'R$ 180',
  cta = 'Agende sua sessao',
  marca = 'SERENA ESTETICA',
  cor = null,
}) => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  const p = preset(nicho);
  const accent = cor || p.accent;
  const fam = familia(p, ANTON);

  // divisoria: entra, vai e volta pra assentar no meio.
  const x = interpolate(f, [10, 60, 96, 130], [12, 88, 30, 55],
    {extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: Easing.inOut(Easing.cubic)});

  const titO = interpolate(f, [4, 18], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const precoT = spring({frame: f - 120, fps, config: {damping: 12, stiffness: 130}});
  const ctaT = interpolate(f, [140, 154], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const esc = interpolate(f, [0, 168], [1.04, 1.12]);

  return (
    <AbsoluteFill style={{background: '#000'}}>
      {/* base = ANTES (dessaturado/frio) */}
      <AbsoluteFill style={{transform: `scale(${esc})`}}>
        <Img src={staticFile(fotoAntes)} style={{width: '100%', height: '100%', objectFit: 'cover', filter: 'grayscale(.85) brightness(.7) contrast(.95)'}} />
      </AbsoluteFill>
      {/* topo = DEPOIS (vivido), recortado da divisoria pra direita */}
      <AbsoluteFill style={{transform: `scale(${esc})`, clipPath: `inset(0 0 0 ${x}%)`}}>
        <Img src={staticFile(fotoDepois)} style={{width: '100%', height: '100%', objectFit: 'cover', filter: `saturate(1.4) brightness(1.08) contrast(1.05) ${p.filtro}`}} />
      </AbsoluteFill>

      {/* linha da divisoria + alca */}
      <div style={{position: 'absolute', top: 0, bottom: 0, left: `${x}%`, width: 4, background: '#fff', boxShadow: '0 0 20px rgba(0,0,0,.6)'}}>
        <div style={{position: 'absolute', top: '50%', left: '50%', transform: 'translate(-50%,-50%)', width: 64, height: 64, borderRadius: '50%', background: '#fff', border: `4px solid ${accent}`, display: 'flex', alignItems: 'center', justifyContent: 'center', fontFamily: POP, fontWeight: 900, color: accent, fontSize: 26}}>⇆</div>
      </div>

      {/* rotulos ANTES / DEPOIS */}
      <div style={{position: 'absolute', top: 40, left: 40, fontFamily: POP, fontWeight: 800, fontSize: 26, letterSpacing: 3, color: '#fff', background: 'rgba(0,0,0,.5)', padding: '8px 18px', borderRadius: 8, opacity: titO}}>ANTES</div>
      <div style={{position: 'absolute', top: 40, right: 40, fontFamily: POP, fontWeight: 800, fontSize: 26, letterSpacing: 3, color: '#111', background: accent, padding: '8px 18px', borderRadius: 8, opacity: titO}}>DEPOIS</div>

      <AbsoluteFill style={{background: 'linear-gradient(to top, rgba(0,0,0,.72), transparent 45%)'}} />

      <div style={{position: 'absolute', bottom: 90, left: 0, right: 0, textAlign: 'center'}}>
        <div style={{fontFamily: fam, fontWeight: 700, fontSize: 72, color: '#fff', opacity: titO, textShadow: '0 6px 24px rgba(0,0,0,.6)'}}>{chamada}</div>
        <div style={{marginTop: 14, display: 'inline-flex', alignItems: 'baseline', gap: 12, transform: `scale(${interpolate(precoT, [0, 1], [0.7, 1])})`, opacity: precoT}}>
          <span style={{fontFamily: POP, fontWeight: 600, fontSize: 22, color: p.creme}}>a partir de</span>
          <span style={{fontFamily: fam, fontWeight: 700, fontSize: 66, color: accent, textShadow: `0 0 40px ${accent}66`}}>{preco}</span>
        </div>
        <div style={{marginTop: 18, opacity: ctaT, transform: `translateY(${interpolate(ctaT, [0, 1], [14, 0])}px)`}}>
          <span style={{fontFamily: POP, fontWeight: 700, fontSize: 24, color: '#111', background: accent, padding: '12px 30px', borderRadius: 40}}>{cta}</span>
          <span style={{fontFamily: fam, fontWeight: 700, fontSize: 22, letterSpacing: 3, color: '#fff', marginLeft: 18}}>{marca}</span>
        </div>
      </div>

      <AbsoluteFill style={{boxShadow: 'inset 0 0 280px 80px rgba(0,0,0,.5)', pointerEvents: 'none'}} />
    </AbsoluteFill>
  );
};
