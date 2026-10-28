import { ref, watch } from 'vue'

// "See it as a member": owners see the Classroom, courses and lessons the way a member
// does (no owner bar, Mark Complete instead of Edit this lesson) until Back to your view.
// Kept for the visit, so moving between pages doesn't drop it.
const KEY = 'mbcom_as_member'
let saved = false
try {
  saved = sessionStorage.getItem(KEY) === '1'
} catch {
  // private windows can refuse storage; the view still works for this page
}
export const asMember = ref(saved)
watch(asMember, (on) => {
  try {
    on ? sessionStorage.setItem(KEY, '1') : sessionStorage.removeItem(KEY)
  } catch {
    // see above
  }
})
