export function groupByDay<T extends { date: string }>(items: T[]): { date: string; items: T[] }[] {
  const groups: { date: string; items: T[] }[] = []
  for (const item of items) {
    const last = groups[groups.length - 1]
    if (last && last.date === item.date) {
      last.items.push(item)
    } else {
      groups.push({ date: item.date, items: [item] })
    }
  }
  return groups
}
