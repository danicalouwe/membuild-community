<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { loadYouTube } from '../lib/youtube.js'
import { t } from '../lib/i18n.js'

/**
 * A YouTube Short played in YouTube's own embedded player (privacy-enhanced
 * mode). Per YouTube's player rules nothing is drawn over the player: the
 * reel's caption and buttons sit below it. It only plays while it is the
 * active reel, which is fully on screen.
 */
const props = defineProps({
  embed: { type: Object, required: true },
  active: { type: Boolean, default: false },
  muted: { type: Boolean, default: true },
})
const emit = defineEmits(['unmuted', 'error'])

const host = ref(null)
const failed = ref(false)
let player = null
let ready = false

function sync() {
  if (!player || !ready) return
  props.muted ? player.mute() : player.unMute()
  if (props.active) player.playVideo()
  else player.pauseVideo()
}

watch(() => [props.active, props.muted], sync)

onMounted(async () => {
  try {
    const YT = await loadYouTube()
    player = new YT.Player(host.value, {
      videoId: props.embed.id,
      host: 'https://www.youtube-nocookie.com',
      width: '100%',
      height: '100%',
      playerVars: {
        playsinline: 1,
        rel: 0,
        controls: 1,
        mute: 1, // muted autoplay is the only kind browsers allow without a tap
        loop: 1,
        playlist: props.embed.id, // required for loop to work on a single video
        origin: location.origin,
      },
      events: {
        onReady: () => {
          ready = true
          sync()
        },
        onStateChange: (e) => {
          // If the member unmutes inside YouTube's own controls, remember it.
          if (e.data === YT.PlayerState.PLAYING && !player.isMuted() && props.muted) emit('unmuted')
        },
        onError: () => {
          failed.value = true
          emit('error')
        },
      },
    })
  } catch {
    failed.value = true
  }
})

onBeforeUnmount(() => player?.destroy?.())
</script>

<template>
  <div class="yt">
    <div ref="host" class="host" />
    <div v-if="failed" class="failed">
      {{ t.__("This video can't be played here.", 'membuild-community') }}
      <a :href="embed.source_url" target="_blank" rel="noopener">{{ t.__('Watch on YouTube', 'membuild-community') }}</a>
    </div>
  </div>
</template>

<style scoped>
.yt {
  position: relative;
  width: 100%;
  height: 100%;
  background: #000;
}

.host,
.yt :deep(iframe) {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  border: 0;
}

.failed {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  color: #ddd;
  text-align: center;
  padding: 24px;
}

.failed a {
  color: #fff;
  font-weight: 650;
  text-decoration: underline;
}
</style>
