// ============================================================
// Lettore minimale di file ZIP nel browser, senza librerie.
//
// Legge l'elenco dei file dalla "central directory" e decomprime solo quelli
// richiesti (metodi "stored" e "deflate", i soli usati dagli ZIP normali) con
// DecompressionStream, disponibile in tutti i browser recenti (Chrome 103+,
// Firefox 113+, Safari 16.4+). Il file ZIP non viene mai caricato per intero in
// memoria: si legge a pezzi con File.slice().
//
//   const voci = await leggiZip(file)   // [{ nome, dimensione, leggi() }]
//   const blob = await voci[0].leggi()  // contenuto del singolo file (Blob)
//
// Non supportati: ZIP64 (archivi oltre 4 GB) e ZIP protetti da password.
// ============================================================

const SIG_EOCD = 0x06054b50   // fine della central directory
const SIG_CEN  = 0x02014b50   // voce della central directory
const SIG_LOC  = 0x04034b50   // intestazione locale del file

export async function leggiZip(file) {
  const lenCoda = Math.min(file.size, 22 + 65535)
  const coda = new DataView(await file.slice(file.size - lenCoda).arrayBuffer())
  let p = lenCoda - 22
  while (p >= 0 && coda.getUint32(p, true) !== SIG_EOCD) p--
  if (p < 0) throw new Error(`"${file.name}" non è un file ZIP valido`)

  const n = coda.getUint16(p + 10, true)
  const dimCd = coda.getUint32(p + 12, true)
  const offCd = coda.getUint32(p + 16, true)
  if (n === 0xffff || offCd === 0xffffffff) throw new Error(`"${file.name}" è troppo grande (ZIP64 non supportato)`)

  const cd = new DataView(await file.slice(offCd, offCd + dimCd).arrayBuffer())
  const decoder = new TextDecoder('utf-8')
  const voci = []
  let q = 0
  for (let i = 0; i < n; i++) {
    if (q + 46 > cd.byteLength || cd.getUint32(q, true) !== SIG_CEN) break
    const flag = cd.getUint16(q + 8, true)
    const metodo = cd.getUint16(q + 10, true)
    const dimComp = cd.getUint32(q + 20, true)
    const dimOrig = cd.getUint32(q + 24, true)
    const lenNome = cd.getUint16(q + 28, true)
    const lenExtra = cd.getUint16(q + 30, true)
    const lenCommento = cd.getUint16(q + 32, true)
    const offLocale = cd.getUint32(q + 42, true)
    const nome = decoder.decode(new Uint8Array(cd.buffer, cd.byteOffset + q + 46, lenNome))
    q += 46 + lenNome + lenExtra + lenCommento
    if (nome.endsWith('/')) continue   // cartella
    const voce = { nome, metodo, dimComp, offLocale, cifrato: (flag & 1) === 1 }
    voci.push({ nome, dimensione: dimOrig, leggi: () => estrai(file, voce) })
  }
  return voci
}

async function estrai(file, v) {
  if (v.cifrato) throw new Error(`"${v.nome}" è protetto da password`)
  const loc = new DataView(await file.slice(v.offLocale, v.offLocale + 30).arrayBuffer())
  if (loc.getUint32(0, true) !== SIG_LOC) throw new Error(`ZIP danneggiato (${v.nome})`)
  const inizio = v.offLocale + 30 + loc.getUint16(26, true) + loc.getUint16(28, true)
  const grezzo = file.slice(inizio, inizio + v.dimComp)
  if (v.metodo === 0) return grezzo
  if (v.metodo === 8) {
    if (typeof DecompressionStream === 'undefined') {
      throw new Error('Il browser non sa aprire gli ZIP: aggiornalo oppure estrai i file a mano')
    }
    return new Response(grezzo.stream().pipeThrough(new DecompressionStream('deflate-raw'))).blob()
  }
  throw new Error(`Compressione ZIP non supportata (metodo ${v.metodo})`)
}
