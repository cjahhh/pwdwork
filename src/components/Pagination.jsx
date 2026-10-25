export default function Pagination({ resource, label = "Results pages", disabled = false }) {
  const meta = resource.data?.meta;
  return (
    <nav aria-label={label} className="flex flex-wrap items-center gap-4 my-5">
      <button
        type="button"
        className="button-secondary"
        disabled={disabled || resource.loading || !meta || resource.page <= 1}
        onClick={() => resource.setPage(resource.page - 1)}
      >
        Previous page
      </button>
      <p role="status" aria-live="polite">
        {resource.loading ? "Loading page..." : meta
          ? `Page ${meta.current_page} of ${meta.last_page} · ${meta.total} results`
          : "Results unavailable"}
      </p>
      <button
        type="button"
        className="button-secondary"
        disabled={disabled || resource.loading || !meta || resource.page >= meta.last_page}
        onClick={() => resource.setPage(resource.page + 1)}
      >
        Next page
      </button>
    </nav>
  );
}
