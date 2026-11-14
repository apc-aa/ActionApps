// How a page follows a sending that runs in the task queue: how often it asks, how long, and after failures.

/** ms: the progress of a sending is read so often; an error only just after the load (Continue takes a moment); nothing after an hour */
export const POLL_EVERY = 5_000;
export const POLL_ERROR_FOR = 120_000;
export const POLL_FOR = 3_600_000;

/** ms to the next reading of the progress after n network failures in a row: 5, 5, 10, 20, then every 60 s */
export const pollDelay = n => ((n === 0) ? POLL_EVERY : ([5_000, 10_000, 20_000][n - 1] ?? 60_000));

/** the progress is read again: in sight, for an hour since the load (ms $since), in sending - in error only just after the load */
export const shouldPoll = ({ state, since, visible }) => visible && (since < POLL_FOR)
    && ((state === 'sending') || ((state === 'error') && (since < POLL_ERROR_FOR)));
