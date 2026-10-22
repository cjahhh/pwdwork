import { useCallback, useEffect, useEffectEvent, useState } from "react";
import { apiErrorMessage } from "../services/api.js";
export function useResource(fetcher, key = "") {
  const fetchLatest = useEffectEvent(() => fetcher());
  const [version, setVersion] = useState(0),
    [state, setState] = useState({
      data: null,
      loading: true,
      error: "",
      key: null,
    });
  const requestKey = String(key) + "|" + version;
  useEffect(() => {
    let active = true;
    Promise.resolve()
      .then(() => {
        if (!active) return;
        setState({ data: null, loading: true, error: "", key: requestKey });
        return fetchLatest();
      })
      .then((data) => {
        if (active)
          setState({ data, loading: false, error: "", key: requestKey });
      })
      .catch((e) => {
        if (active)
          setState({
            data: null,
            loading: false,
            error: apiErrorMessage(e),
            key: requestKey,
          });
      });
    return () => {
      active = false;
    };
  }, [requestKey]);
  const reload = useCallback(() => setVersion((v) => v + 1), []);
  const setData = useCallback(
    (update) =>
      setState((s) => ({
        ...s,
        data: typeof update === "function" ? update(s.data) : update,
      })),
    [],
  );
  return {
    ...(state.key === requestKey
      ? state
      : { data: null, loading: true, error: "" }),
    setData,
    reload,
  };
}
