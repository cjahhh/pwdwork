import Icon from '../Icon.jsx';
const steps = [
  ['user', 'Create your profile', 'Highlight your skills and optional experience. Choose whether to share profile accommodation preferences for each application.'],
  ['search', 'Search and apply', 'Explore published jobs, review employer-reported workplace support and apply to opportunities that interest you.'],
  ['briefcase', 'Track your progress', 'Follow application updates, prepare for interviews and discuss practical recruitment arrangements privately.'],
];
export default function HowItWorks() {
  return <section id="how-it-works" className="how-section"><div className="section-container"><p className="eyebrow">From first step to next opportunity</p><h2>How PWDWork Works</h2><div className="benefit-grid">{steps.map(([icon,title,description],index) => <article key={title}><span className="metric-icon"><Icon name={icon} /></span><p className="step-number">Step 0{index + 1}</p><h3>{title}</h3><p>{description}</p></article>)}</div></div></section>;
}