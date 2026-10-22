export default function RequestState({ resource }) {
  if (resource.loading) return <p role="status">Loading...</p>;
  if (resource.error)
    return (
      <div role="alert">
        <p>{resource.error}</p>
        <button type="button" onClick={resource.reload}>
          Try again
        </button>
      </div>
    );
  return null;
}
