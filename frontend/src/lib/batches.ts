/** Roda `task` para cada id com no máximo `concurrency` em paralelo; não para na primeira falha. */
export async function runInBatches<T>(
  ids: T[],
  task: (id: T) => Promise<unknown>,
  concurrency = 4,
): Promise<{ succeeded: T[]; failed: T[] }> {
  const succeeded: T[] = []
  const failed: T[] = []
  let cursor = 0

  async function worker() {
    while (cursor < ids.length) {
      const id = ids[cursor]
      cursor += 1
      try {
        await task(id)
        succeeded.push(id)
      } catch {
        failed.push(id)
      }
    }
  }

  await Promise.all(Array.from({ length: Math.min(concurrency, ids.length) }, worker))

  const order = (list: T[]) => ids.filter((id) => list.includes(id))
  return { succeeded: order(succeeded), failed: order(failed) }
}
