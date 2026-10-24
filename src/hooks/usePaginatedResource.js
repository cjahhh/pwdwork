import { useEffect, useState } from "react";
import { useResource } from "./useResource.js";

export function usePaginatedResource(fetcher, params = {}, scope = "") {
  const filterKey = scope + "|" + JSON.stringify(params);
  const [cursor, setCursor] = useState({ key: filterKey, page: 1 });
  const page = cursor.key === filterKey ? cursor.page : 1;
  const resource = useResource(
    () => fetcher({ ...params, page }),
    filterKey + "|" + page,
  );
  const lastPage = resource.data?.meta.last_page;
  useEffect(() => {
    // A removal/status change can empty the last page.
    if (lastPage && page > lastPage) {
      // Reconcile asynchronously after the server confirms a changed page count.
      const timeout = setTimeout(() => setCursor({ key: filterKey, page: lastPage }), 0);
      return () => clearTimeout(timeout);
    }
  }, [lastPage, page, filterKey]);
  return {
    ...resource,
    page,
    setPage: (next) => setCursor({ key: filterKey, page: next }),
  };
}
