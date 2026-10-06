/**
 * image.js —— 浏览器端双尺寸处理（SPEC §3.2 张力 C / §9.6）
 *
 * 【为什么这一步不能省】
 * 1. 钛盘不剥离 EXIF（TttttAdapter::stripsExif() === false）。手机拍的图带着
 *    精确 GPS 坐标，直传等于把家庭住址发到公网 —— 所以这是**必需步骤**，不是优化。
 *    唯一可靠的办法就是 Canvas 重绘：画一遍，元数据自然全没了。
 * 2. 钛盘没有缩略图参数（supportsTransform() === false）。列表页要小图，
 *    就只能自己生成一份再单独上传。这就是「一张图上传统两次」的由来。
 * 3. 顺带把尺寸压到合理范围，避免原图 4000px 宽直接进列表页。
 *
 * 画两次的结果一次性返回，调用方凭此组装 multipart 上传。
 */

/** 浏览器是否支持 WebP 编码（老 Safari 不支持，需回退 JPEG） */
let webpSupport = null

export function supportsWebp() {
  if (webpSupport !== null) return webpSupport
  try {
    const canvas = document.createElement('canvas')
    canvas.width = 1
    canvas.height = 1
    webpSupport = canvas.toDataURL('image/webp').startsWith('data:image/webp')
  } catch {
    webpSupport = false
  }
  return webpSupport
}

/**
 * 解码文件为可绘制的位图。
 *
 * 【EXIF 方向】createImageBitmap 的 imageOrientation:'from-image' 会**应用**
 * 方向信息后解码，所以画出来就是正的。这是它相对 <img> 的优势：
 * <img> + canvas 在不同浏览器上对方向的处置历史上有过不一致。
 */
async function decode(file) {
  if (typeof createImageBitmap === 'function') {
    try {
      return await createImageBitmap(file, { imageOrientation: 'from-image' })
    } catch {
      // 某些浏览器不认这个选项，或该格式不支持 → 走下面的兜底
    }
  }

  // 兜底：<img> + objectURL。用完必须 revoke，否则整张图的内存不会释放。
  const url = URL.createObjectURL(file)
  try {
    const img = new Image()
    img.decoding = 'sync'
    await new Promise((resolve, reject) => {
      img.onload = resolve
      img.onerror = () => reject(new Error('图片解码失败，文件可能已损坏'))
      img.src = url
    })
    return img
  } finally {
    URL.revokeObjectURL(url)
  }
}

/**
 * 按最长边缩放重绘并编码。
 * 原图比目标还小就**不放大** —— 放大只会让文件变大、画质变糊。
 *
 * @returns {{blob: Blob, width: number, height: number, mime: string}}
 */
async function render(source, sourceWidth, sourceHeight, maxEdge, mime, quality) {
  const scale = Math.min(1, maxEdge / Math.max(sourceWidth, sourceHeight))
  const width = Math.max(1, Math.round(sourceWidth * scale))
  const height = Math.max(1, Math.round(sourceHeight * scale))

  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height

  const ctx = canvas.getContext('2d')
  // 缩放时开高质量插值；1:1 时也无害
  ctx.imageSmoothingEnabled = true
  ctx.imageSmoothingQuality = 'high'
  ctx.drawImage(source, 0, 0, width, height)

  const blob = await new Promise((resolve, reject) => {
    canvas.toBlob(
      (b) => (b ? resolve(b) : reject(new Error('图片编码失败，请换一张试试'))),
      mime,
      quality
    )
  })

  return { blob, width, height, mime }
}

/** 生成远端文件名。服务端还会再随机化一次，这里只保证扩展名正确。 */
function fileName(prefix, mime) {
  const ext = mime === 'image/webp' ? 'webp' : mime === 'image/png' ? 'png' : 'jpg'
  return `${prefix}-${Date.now()}.${ext}`
}

/**
 * 处理一张待上传的图片。
 *
 * @param {File} file
 * @param {{thumbEdge?: number, fullEdge?: number, thumbQuality?: number, fullQuality?: number}} opts
 * @returns {Promise<{
 *   full: File, thumb: File,
 *   width: number, height: number,
 *   thumbWidth: number, thumbHeight: number,
 *   originalSize: number, compressedSize: number,
 *   originalName: string
 * }>}
 */
export async function prepareImage(file, opts = {}) {
  const thumbEdge = opts.thumbEdge || 800
  const fullEdge = opts.fullEdge || 2560
  const thumbQuality = opts.thumbQuality ?? 0.8
  const fullQuality = opts.fullQuality ?? 0.85

  if (!file.type.startsWith('image/')) {
    throw new Error('请选择图片文件')
  }
  // GIF 一动就没了动效，直接原样返回、不重绘（代价是 EXIF 得不到清理）
  if (file.type === 'image/gif') {
    return {
      full: file,
      thumb: file,
      width: 0,
      height: 0,
      thumbWidth: 0,
      thumbHeight: 0,
      originalSize: file.size,
      compressedSize: file.size,
      originalName: file.name,
      gifPassthrough: true,
    }
  }

  const bitmap = await decode(file)
  const sourceWidth = bitmap.width || bitmap.naturalWidth
  const sourceHeight = bitmap.height || bitmap.naturalHeight

  if (!sourceWidth || !sourceHeight) {
    throw new Error('无法读取图片尺寸，文件可能已损坏')
  }

  // WebP 压缩率明显更好；不支持时才退回 JPEG。
  // PNG 源图若带透明通道，转 JPEG 会变黑底 —— 故有 alpha 时保留 PNG。
  const usePng =
    file.type === 'image/png' && (await hasAlpha(bitmap, sourceWidth, sourceHeight))
  const mime = usePng ? 'image/png' : supportsWebp() ? 'image/webp' : 'image/jpeg'
  const q = usePng ? undefined : fullQuality
  const tq = usePng ? undefined : thumbQuality

  const full = await render(bitmap, sourceWidth, sourceHeight, fullEdge, mime, q)
  const thumb = await render(bitmap, sourceWidth, sourceHeight, thumbEdge, mime, tq)

  // 位图用完即释放。大图不释放会让连续上传几张后浏览器就吃掉几百 MB。
  if (typeof bitmap.close === 'function') bitmap.close()

  const baseName = file.name.replace(/\.[^.]+$/, '').slice(0, 40) || 'image'

  return {
    full: new File([full.blob], fileName(baseName, full.mime), { type: full.mime }),
    thumb: new File([thumb.blob], fileName(`${baseName}-thumb`, thumb.mime), { type: thumb.mime }),
    width: full.width,
    height: full.height,
    thumbWidth: thumb.width,
    thumbHeight: thumb.height,
    originalSize: file.size,
    compressedSize: full.blob.size,
    originalName: file.name,
  }
}

/** 位图是否含半透明像素（抽样检测，全量扫描太慢） */
async function hasAlpha(bitmap, width, height) {
  try {
    const canvas = document.createElement('canvas')
    canvas.width = width
    canvas.height = height
    const ctx = canvas.getContext('2d')
    ctx.drawImage(bitmap, 0, 0)

    // 抽样步长：最多看 1 万个像素点，足以判断有无透明通道
    const step = Math.max(1, Math.floor(Math.sqrt((width * height) / 10000)))
    const data = ctx.getImageData(0, 0, width, height).data
    for (let y = 0; y < height; y += step) {
      for (let x = 0; x < width; x += step) {
        if (data[(y * width + x) * 4 + 3] < 250) return true
      }
    }
    return false
  } catch {
    // 跨域或内存不足 → 保守当作有 alpha，宁可存 PNG 也别变黑底
    return true
  }
}

/** 人类可读的体积 */
export function formatSize(bytes) {
  if (!bytes && bytes !== 0) return ''
  if (bytes >= 1048576) return `${(bytes / 1048576).toFixed(1)} MB`
  if (bytes >= 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${bytes} B`
}
