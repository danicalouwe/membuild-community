const MAX_SIDE = 2048

/**
 * Downscales photos in the browser before upload, so the server stores a
 * sensible size and the feed knows each image's exact dimensions up front.
 *
 * @param {File} file
 * @returns {Promise<{blob: Blob, width: number, height: number}>}
 */
export async function prepareImage(file) {
  // Animated GIFs would lose their animation on a canvas, so keep them as-is.
  if (file.type === 'image/gif') {
    const { width, height } = await naturalSize(file)
    return { blob: file, width, height }
  }

  const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' })
  const scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height))
  const width = Math.round(bitmap.width * scale)
  const height = Math.round(bitmap.height * scale)

  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height
  canvas.getContext('2d').drawImage(bitmap, 0, 0, width, height)
  bitmap.close?.()

  let blob = await toBlob(canvas, 'image/webp', 0.85)
  if (!blob || blob.type !== 'image/webp') blob = await toBlob(canvas, 'image/jpeg', 0.85)
  return { blob, width, height }
}

export function toBlob(canvas, type, quality) {
  return new Promise((resolve) => canvas.toBlob(resolve, type, quality))
}

function naturalSize(file) {
  return new Promise((resolve, reject) => {
    const img = new Image()
    img.onload = () => {
      resolve({ width: img.naturalWidth, height: img.naturalHeight })
      URL.revokeObjectURL(img.src)
    }
    img.onerror = reject
    img.src = URL.createObjectURL(file)
  })
}
