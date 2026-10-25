const features = [
  ['Find opportunities', 'Browse currently published jobs and compare employer-reported work arrangements and accessibility information.'],
  ['Prepare applications', 'Create resumes and choose which saved version to share with each application.'],
  ['Discuss arrangements', 'Request practical recruitment or interview accommodations with separate sharing controls.'],
];
export default function ImpactStats() {
  return <section className="bg-brand-gray-bg py-12 border-b border-gray-200"><div className="max-w-6xl mx-auto px-6">
    <h2 className="text-3xl font-black text-brand-dark mb-6">What you can do on PWDWork</h2>
    <div className="grid md:grid-cols-3 gap-6">{features.map(([title, description]) => <article key={title} className="panel bg-white"><h3 className="text-xl font-bold">{title}</h3><p>{description}</p></article>)}</div>
    <p className="mt-4 text-gray-700">Public impact statistics will be added only when supported by verified records and a clear reporting method.</p>
  </div></section>;
}