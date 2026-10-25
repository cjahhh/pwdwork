export default function RequestState({ resource }) {
  if (resource.loading) return <p role="status" className="request-feedback loading-state"><span className="loading-mark" aria-hidden="true" />Loading...</p>;
  if (resource.error)
    return (
      <div role="alert" className="request-feedback error-state">
        <p>{resource.error}</p>
        <button type="button" onClick={resource.reload}>
          Try again
        </button>
      </div>
    );
  return null;
}
