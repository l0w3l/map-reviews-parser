const statuses: Record<string, string> = {
    queued: 'В очереди',
    running: 'Собираем отзывы',
    retrying: 'Повторная попытка',
    completed: 'Обновлено',
    failed: 'Ошибка обновления',
    blocked: 'Доступ ограничен',
};
export const statusLabel = (status: string) => statuses[status] ?? status;
export const number = (value: number | null | undefined) =>
    value == null ? '—' : new Intl.NumberFormat('ru-RU').format(value);
export const date = (value: string | null) =>
    value
        ? new Date(
              value.includes('T') ? value : value.replace(' ', 'T') + 'Z',
          ).toLocaleString('ru-RU', { dateStyle: 'medium', timeStyle: 'short' })
        : 'Ещё не обновлялось';
