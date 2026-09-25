import {AbsoluteFill, Img, staticFile, useCurrentFrame, useVideoConfig, interpolate, spring} from 'remotion';
import {loadAnton, loadPoppins} from './fontes';
import {preset, familia} from './presets';

const ANTON = loadAnton().fontFamily;
const POP = loadPoppins().fontFamily;

// COMBO/CARDAPIO — grade de itens (foto + nome + preco). Forte pra
// lanchonete, menu, catalogo. Duracao alvo: 168f (5.6s).
export const Combo = ({
  nicho = 'comida',
  titulo = 'CARDAPIO',
  itens = [
    {foto: 'burger.jpg', nome: 'Smash Classico', preco: 'R$ 28'},
    {foto: 'comida.jpg', nome: 'Duplo Bacon', preco: 'R$ 34'},
    {foto: 'detail.jpg', nome: 'Cheddar Melt', preco: 'R$ 32'},
    {foto: 'hero.jpg', nome: 'Combo Familia', preco: 'R$ 89'},
  ],
  cta = 'Peca pelo WhatsApp',
  marca = 'BURGER HOUSE',
  cor = null,
}) => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  const p = preset(nicho);
  const accent = cor || p.accent;
  const fam = familia(p, ANTON);

  const titO = interpolate(f, [4, 18], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const ctaT = interpolate(f, [130, 146], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});
  const cols = itens.length <= 2 ? itens.length : 2;

  return (
    <AbsoluteFill style={{background: p.bg}}>
      <AbsoluteFill style={{transform: 'scale(1.2)'}}>
        <Img src={staticFile(itens[0]?.foto || 'burger.jpg')} style={{width: '100%', height: '100%', objectFit: 'cover', filter: `blur(30px) brightness(.32) ${p.filtro}`}} />
      </AbsoluteFill>

      {/* cabecalho */}
      <div style={{position: 'absolute', top: 60, left: 0, right: 0, textAlign: 'center', opacity: titO}}>
        <div style={{fontFamily: fam, fontWeight: 700, fontSize: 92, color: '#fff', textShadow: '0 6px 24px rgba(0,0,0,.6)'}}>{titulo}</div>
        <div style={{width: 120, height: 5, background: accent, margin: '10px auto 0', borderRadius: 3}} />
      </div>

      {/* grade */}
      <AbsoluteFill style={{alignItems: 'center', justifyContent: 'center', paddingTop: 60}}>
        <div style={{display: 'grid', gridTemplateColumns: `repeat(${cols}, 400px)`, gap: 28}}>
          {itens.map((it, i) => {
            const s = spring({frame: f - (16 + i * 8), fps, config: {damping: 13, stiffness: 110}});
            return (
              <div key={i} style={{background: 'rgba(255,255,255,.06)', border: `1px solid ${accent}44`, borderRadius: 22, overflow: 'hidden',
                transform: `scale(${interpolate(s, [0, 1], [0.8, 1])}) translateY(${interpolate(s, [0, 1], [30, 0])}px)`, opacity: s,
                display: 'flex', alignItems: 'center', gap: 18, padding: 16}}>
                <div style={{width: 120, height: 120, borderRadius: 16, overflow: 'hidden', flexShrink: 0}}>
                  <Img src={staticFile(it.foto)} style={{width: '100%', height: '100%', objectFit: 'cover', filter: p.filtro}} />
                </div>
                <div style={{flex: 1}}>
                  <div style={{fontFamily: POP, fontWeight: 800, fontSize: 30, color: '#fff', lineHeight: 1.1}}>{it.nome}</div>
                  <div style={{fontFamily: fam, fontWeight: 700, fontSize: 48, color: accent, marginTop: 4}}>{it.preco}</div>
                </div>
              </div>
            );
          })}
        </div>
      </AbsoluteFill>

      {/* rodape */}
      <div style={{position: 'absolute', bottom: 64, left: 0, right: 0, textAlign: 'center', opacity: ctaT, transform: `translateY(${interpolate(ctaT, [0, 1], [16, 0])}px)`}}>
        <span style={{fontFamily: POP, fontWeight: 700, fontSize: 26, color: '#111', background: accent, padding: '12px 32px', borderRadius: 40}}>{cta}</span>
        <span style={{fontFamily: fam, fontWeight: 700, fontSize: 26, letterSpacing: 3, color: '#fff', marginLeft: 18}}>{marca}</span>
      </div>

      <AbsoluteFill style={{boxShadow: 'inset 0 0 300px 90px rgba(0,0,0,.5)', pointerEvents: 'none'}} />
    </AbsoluteFill>
  );
};
