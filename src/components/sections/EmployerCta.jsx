import { Link } from 'react-router-dom';
import Icon from '../Icon.jsx';
export default function EmployerCta() {
  return <section id="employer-cta" className="section-container"><div className="employer-banner"><div><p className="eyebrow">For recruiters & organizations</p><h2>Open opportunities. Stronger teams.</h2><p>Post roles with clear descriptions and employer-reported workplace support. Connect with applicants and manage each step of recruitment.</p><Link className="banner-link" to="/about">About inclusive recruitment on PWDWork</Link></div><Link className="primary-button" to="/register?role=employer">Post a Job Opening <Icon name="arrow" /></Link></div></section>;
}