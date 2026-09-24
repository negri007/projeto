import {AbsoluteFill, Img, staticFile, useCurrentFrame, useVideoConfig, interpolate, spring} from 'remotion';
import {loadFont as loadAnton} from '@remotion/google-fonts/Anton';
import {loadFont as loadPoppins} from '@remotion/google-fonts/Poppins';
import {preset, familia, corTexto} from './presets';
import {Foto} from './Foto';

const ANTON = loadAnton().fontFamily;
const POP = loadPoppins().fontFamily;

// FLASH — anuncio de 1 cena (plano Basico).
// Recebe os dados da loja + o nicho. O preset do nicho define o estilo;
// `variante` (0..1) troca o layout pra dois anuncios do mesmo nicho nao
// sairem iguais. Duracao alvo: 150 frames (5s @ 30fps).
export const Flash = ({
  nicho = 'comida',
  produto = 'Produto',
  chamada = ['SEU', 'PRODUTO'],
  preco = 'R$ 00,00',
  cta = 'Peca pelo WhatsApp',
  marca = 'SUA MARCA',
  foto = 'burger.jpg',
  cor = null,
  variante = 0,
  ajuste = 'preencher',
  foco = null,
}) => {
  const f = useCurrentFrame();
  const {fps, durationInFrames: dur} = useVideoConfig();
  const p = preset(nicho);
  const accent = cor || p.accent;
  const fam = familia(p, ANTON);
  const palavras = Array.isArray(chamada) ? chamada : String(chamada).split(' ');

  // Ken Burns: zoom lento na foto o video inteiro.
  const esc = interpolate(f, [0, dur], [1.06, 1.2]);
  const fadeIn = interpolate(f, [0, 10], [0, 1], {extrapolateRight: 'clamp'});
  const badge = interpolate(f, [6, 22], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});

  // Preco entra com pop.
  const precoT = spring({frame: f - 70, fps, config: {damping: 11, stiffness: 140}});
  // CTA no fim.
  const ctaT = interpolate(f, [100, 116], [0, 1], {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'});

  const centro = variante === 1;

  return (
    <AbsoluteFill style={{background: p.bg, opacity: fadeIn}}>
      {/* CAMADA 1 — foto base (Preencher x Foto inteira + foco/zoom) */}
      <AbsoluteFill style={{transform: `scale(${esc})`}}>
        <Foto src={foto} filtro={p.filtro} ajuste={ajuste} foco={foco} />
      </AbsoluteFill>

      {/* leitura: escurece pra o texto ler */}
      <AbsoluteFill style={{background: centro
        ? 'radial-gradient(circle at 50% 60%, transparent 20%, rgba(0,0,0,.65) 90%)'
        : 'linear-gradient(to top, rgba(0,0,0,.72), transparent 55%)'}} />

      {/* badge do nicho */}
      <div style={{position: 'absolute', top: 72, left: centro ? 0 : 60, right: centro ? 0 : 'auto',
        textAlign: centro ? 'center' : 'left', fontFamily: POP, fontWeight: 800, fontSize: 28,
        letterSpacing: 6, color: accent, opacity: badge}}>{p.badge}</div>

      {/* CAMADAS 2 e 3 numa PILHA UNICA ancorada embaixo.
          Flex column: titulo em cima, preco/CTA/marca embaixo, empilhados
          de baixo pra cima. Nunca sobrepoem, mesmo com titulo de 2 linhas
          ou preco longo — cada bloco reserva seu proprio espaco. */}
      <AbsoluteFill style={{display: 'flex', flexDirection: 'column',
        justifyContent: centro ? 'center' : 'flex-end',
        alignItems: centro ? 'center' : 'flex-start',
        textAlign: centro ? 'center' : 'left',
        padding: centro ? '0 64px' : '0 56px 92px'}}>

        {/* titulo (chamada), palavra por palavra */}
        <div>
          {palavras.map((w, i) => {
            const s = spring({frame: f - (10 + i * 9), fps, config: {damping: 14, stiffness: 120}});
            const x = interpolate(s, [0, 1], [i % 2 ? 480 : -480, 0]);
            return (
              <div key={i} style={{fontFamily: fam, fontWeight: 700, fontSize: centro ? 118 : 140,
                color: i === palavras.length - 1 ? accent : '#fff', lineHeight: 0.92,
                transform: `translateX(${x}px)`, textShadow: '0 8px 30px rgba(0,0,0,.6)'}}>{w}</div>
            );
          })}
        </div>

        {/* espaco garantido entre titulo e preco */}
        <div style={{display: 'flex', flexDirection: 'column',
          alignItems: centro ? 'center' : 'flex-start', gap: 16, marginTop: 34}}>
          <div style={{display: 'inline-flex', alignItems: 'baseline', gap: 12,
            transform: `scale(${interpolate(precoT, [0, 1], [0.6, 1])})`, opacity: precoT}}>
            <span style={{fontFamily: POP, fontWeight: 600, fontSize: 22, color: p.creme}}>a partir de</span>
            <span style={{fontFamily: fam, fontWeight: 700, fontSize: 74, color: accent, lineHeight: 1,
              textShadow: `0 0 40px ${accent}66`}}>{preco}</span>
          </div>
          <div style={{fontFamily: POP, fontWeight: 700, fontSize: 26, color: corTexto(accent), background: accent,
            padding: '12px 30px', borderRadius: 40, opacity: ctaT,
            transform: `translateY(${interpolate(ctaT, [0, 1], [16, 0])}px)`}}>{cta}</div>
          <div style={{fontFamily: fam, fontWeight: 700, fontSize: 24, color: '#fff', letterSpacing: 3,
            opacity: ctaT * 0.85}}>{marca}</div>
        </div>
      </AbsoluteFill>

      {/* grao de filme + vinheta (o toque "IA") */}
      <AbsoluteFill style={{boxShadow: 'inset 0 0 260px 70px rgba(0,0,0,.5)', pointerEvents: 'none'}} />
    </AbsoluteFill>
  );
};
