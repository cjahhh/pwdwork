import { useEffect, useRef } from "react";

export function useAutoRefresh(fetcher, onData, interval = 10000) {
  const fetcherRef = useRef(fetcher);
  const onDataRef = useRef(onData);

  fetcherRef.current = fetcher;
  onDataRef.current = onData;

  useEffect(() => {
    let active = true;

    async function refresh() {
      try {
        const data = await fetcherRef.current();
        if (active) onDataRef.current(data);
      } catch {
        // Keep the current screen usable while the API is unavailable.
      }
    }

    refresh();
    const timer = window.setInterval(refresh, interval);
    return () => {
      active = false;
      window.clearInterval(timer);
    };
  }, [interval]);
}
