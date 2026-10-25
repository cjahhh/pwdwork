const features = [
  ['Find opportunities', 'Browse currently published jobs and compare employer-reported work arrangements and accessibility information.'],
  ['Prepare applications', 'Create resumes and choose which saved version to share with each application.'],
  ['Discuss arrangements', 'Request practical recruitment or interview accommodations with separate sharing controls.'],
];
export default function ImpactStats() {
  return <section className="capabilities-section"><div className="section-container">
    <p className="eyebrow">Built for your next step</p><h2>What you can do on PWDWork</h2>
    <div className="benefit-grid">{features.map(([title, description]) => <article key={title}><h3>{title}</h3><p>{description}</p></article>)}</div>
  </div></section>;
}
