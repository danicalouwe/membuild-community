<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

// Confetti on the podium. When the Leaderboard opens (`burst`), it pops up from behind 1st
// place for about three seconds; after that a few soft pieces keep drifting slowly down
// behind the cards, like a quiet celebration. Skipped for people who ask their device for
// less motion. One small canvas, nothing to download; it pauses while the tab is hidden.
const props = defineProps({ burst: { type: Boolean, default: false } })
const canvas = ref(null)
const calm = ref(!props.burst)
const COLORS = ['#f2c14e', '#c3c9d2', '#d38b55', '#c8f53e', '#ffffff', '#ff7a90']
let raf = 0

const pick = () => COLORS[(Math.random() * COLORS.length) | 0]

onMounted(() => {
  if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return
  const el = canvas.value
  const ctx = el?.getContext('2d')
  if (!ctx) return
  const dpr = Math.min(window.devicePixelRatio || 1, 2)
  const w = el.clientWidth
  const h = el.clientHeight
  el.width = w * dpr
  el.height = h * dpr
  ctx.scale(dpr, dpr)

  const pops = props.burst
    ? Array.from({ length: w < 500 ? 70 : 110 }, () => {
        const angle = -Math.PI / 2 + (Math.random() - 0.5) * 2.2
        const speed = 5 + Math.random() * 7
        return { x: w / 2 + (Math.random() - 0.5) * 60, y: h * 0.45, vx: Math.cos(angle) * speed, vy: Math.sin(angle) * speed, size: 5 + Math.random() * 5, turn: Math.random() * Math.PI, spin: (Math.random() - 0.5) * 0.3, color: pick(), round: Math.random() < 0.3 }
      })
    : []
  // The quiet ones: few, small, slow, faint, swaying a little as they fall, then round again from the top.
  const drift = () => ({ x: Math.random() * w, y: -10 - Math.random() * h, vy: 0.25 + Math.random() * 0.35, sway: Math.random() * Math.PI * 2, size: 4 + Math.random() * 3, turn: Math.random() * Math.PI, spin: (Math.random() - 0.5) * 0.03, color: pick(), round: Math.random() < 0.3 })
  const calmBits = Array.from({ length: w < 500 ? 14 : 22 }, () => {
    const b = drift()
    b.y = Math.random() * h // spread out already, so they don't all arrive together
    return b
  })

  const start = performance.now()
  const BURST = 3000

  function draw(b, alpha) {
    ctx.save()
    ctx.globalAlpha = alpha
    ctx.translate(b.x, b.y)
    ctx.rotate(b.turn)
    ctx.fillStyle = b.color
    if (b.round) {
      ctx.beginPath()
      ctx.arc(0, 0, b.size / 2.4, 0, Math.PI * 2)
      ctx.fill()
    } else {
      // A paper strip that seems to flip as it turns.
      const f = Math.abs(Math.cos(b.turn * 2))
      ctx.fillRect(-b.size / 2, (-b.size / 4) * f, b.size, (b.size / 2) * f + 1)
    }
    ctx.restore()
  }

  function frame(now) {
    const age = now - start
    ctx.clearRect(0, 0, w, h)
    if (age < BURST && pops.length) {
      const fade = age > BURST - 800 ? (BURST - age) / 800 : 1
      for (const b of pops) {
        b.vy += 0.22
        b.vx *= 0.985
        b.vy *= 0.985
        b.x += b.vx
        b.y += b.vy
        b.turn += b.spin
        draw(b, fade)
      }
    } else if (!calm.value) {
      calm.value = true // from now on behind the cards
    }
    // The quiet confetti fades in as the burst ends (or straight away without a burst).
    const calmIn = pops.length ? Math.min(1, Math.max(0, (age - BURST + 1200) / 1500)) : Math.min(1, age / 1500)
    if (calmIn > 0) {
      for (const b of calmBits) {
        b.y += b.vy
        b.sway += 0.012
        b.x += Math.sin(b.sway) * 0.25
        b.turn += b.spin
        if (b.y > h + 10) Object.assign(b, drift(), { y: -10 })
        draw(b, 0.35 * calmIn)
      }
    }
    raf = requestAnimationFrame(frame)
  }
  raf = requestAnimationFrame(frame)
})

onBeforeUnmount(() => cancelAnimationFrame(raf))
</script>

<template>
  <canvas ref="canvas" class="confetti" :class="{ calm }" aria-hidden="true" />
</template>

<style scoped>
.confetti {
  position: absolute;
  inset: -60px -24px -20px;
  z-index: 2;
  width: calc(100% + 48px);
  height: calc(100% + 80px);
  pointer-events: none;
}

/* After the burst: behind the podium cards. */
.confetti.calm {
  z-index: 0;
}
</style>
