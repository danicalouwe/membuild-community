/**
 * v-auto-grow: a textarea that grows with its text. Browsers that support
 * `field-sizing: content` do this in CSS; this fills in for the rest
 * (Firefox, older Safari). Any CSS max-height still caps it.
 */
const native = typeof CSS !== 'undefined' && CSS.supports?.('field-sizing', 'content')

function fit(el) {
  el._grownFor = el.value
  el.style.height = 'auto'
  el.style.height = `${el.scrollHeight + el.offsetHeight - el.clientHeight}px`
}

export const vAutoGrow = native
  ? {}
  : {
      mounted(el) {
        el._grow = () => fit(el)
        el.addEventListener('input', el._grow)
        fit(el)
      },
      // A v-model cleared or filled from code (sent, emoji inserted) doesn't fire input.
      updated(el) {
        if (el.value !== el._grownFor) fit(el)
      },
      unmounted(el) {
        el.removeEventListener('input', el._grow)
      },
    }
