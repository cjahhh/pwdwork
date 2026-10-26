import { useRef, useState } from 'react';
import { Link, NavLink, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext.jsx';
import { useUi } from '../context/UiContext.jsx';
import { apiErrorMessage } from '../services/api.js';
import { accountPath } from '../services/auth.js';
import Icon from './Icon.jsx';

const roleNames = { applicant: 'Jobseeker workspace', employer: 'Employer workspace', admin: 'Administration' };
const roleLinks = {
  applicant: [['Overview','/applicant','grid'],['Find Jobs','/jobs','search'],['Recommended Jobs','/applicant/recommendations','briefcase'],['Applications','/applicant/applications','file'],['Saved Jobs','/applicant/saved-jobs','heart'],['Resumes','/applicant/resumes','file'],['Accommodation Requests','/applicant/accommodation-requests','message'],['Profile','/applicant/profile','user']],
  employer: [['Overview','/employer','grid'],['Job Listings','/employer/listings','briefcase'],['Applicants','/employer/applicants','user'],['Accommodation Requests','/employer/accommodation-requests','message'],['Company Profile','/employer/profile','shield']],
  admin: [['Admin Dashboard','/admin','grid'],['Employer verification','/admin/employers','shield'],['Job moderation','/admin/jobs','briefcase'],['User management','/admin/users','user'],['Reports and complaints','/admin/reports','message'],['Audit logs','/admin/audit','clock']],
};
function Brand({ workspace = false }) {
  return <Link to="/" className={`brand-lockup ${workspace ? 'brand-inverse' : ''}`} aria-label="PWDWork Homepage"><span className="brand-mark"><Icon name="briefcase" size={25} /><i /></span><span><strong>PWD<span>Work</span></strong><small>Inclusive jobs. Real possibility.</small></span></Link>;
}
export default function Navbar({ application = false }) {
  const [open, setOpen] = useState(false);
  const toggle = useRef(null);
  const { user, ready, logout } = useAuth();
  const { showToast } = useUi();
  const navigate = useNavigate();
  const { pathname } = useLocation();
  const dashboard = user ? accountPath(user) : '/login';
  const links = application ? [...(roleLinks[user?.role] || []), ...(user?.role !== 'admin' ? [['Reports','/reports','shield']] : []), ['Notifications','/notifications','bell']]
    : [['Home','/','grid'],['Find Jobs','/jobs','search'],['For Employers','/employers','briefcase'],['About','/about','user']];
  async function onLogout() {
    try { await logout(); } catch (error) { showToast('Logout failed', apiErrorMessage(error)); return; }
    setOpen(false); showToast('Logged out', 'Your session has ended.'); navigate('/');
  }
  function closeMenu(to) {
    setOpen(false);
    if (to === pathname) requestAnimationFrame(() => toggle.current?.focus());
  }
  return <header className={application ? 'app-chrome' : 'public-header'} aria-busy={!ready} onKeyDown={event => { if (event.key === 'Escape' && open) { setOpen(false); toggle.current?.focus(); } }}>
    {application && <div className="app-brand"><Brand workspace /><p>{roleNames[user?.role]}</p></div>}
    <div className={application ? 'app-topbar' : 'public-topbar'}>
      <div className={application ? 'mobile-brand' : ''}><Brand /></div>
      {application && <div className="workspace-heading"><span className="workspace-indicator" /><span>{roleNames[user?.role]}</span></div>}
      {!application && <nav className="public-desktop-links" aria-label="Main Navigation">{links.map(([label,to]) => <NavLink end={to === '/'} key={to} to={to}>{label}</NavLink>)}</nav>}
      <div className="account-actions">{user ? <><span className="account-avatar" aria-hidden="true">{user.name.slice(0,1).toUpperCase()}</span><span className="account-name">{user.name}</span>{!application && <Link className="button-secondary" to={dashboard}>Workspace</Link>}<button type="button" className="logout-button" onClick={onLogout}>Log out</button></> : <><Link className="login-link" to="/login">Log In</Link><Link className="primary-button" to="/register">Get started <Icon name="arrow" size={16} /></Link></>}
        <button ref={toggle} type="button" className="menu-toggle" aria-label="Toggle menu" aria-expanded={open} aria-controls="workspace-navigation" onClick={() => setOpen(value => !value)}><Icon name="menu" /></button>
      </div>
    </div>
    <nav id="workspace-navigation" className={`${application ? 'app-sidebar' : 'public-mobile-links'} ${open ? 'menu-open' : ''}`} aria-label={application ? 'Main Navigation' : 'Mobile navigation'}><div className="sidebar-inner">{application && <p className="nav-section-label">Your workspace</p>}{links.map(([label,to,icon]) => <NavLink key={to} end={to === '/' || to === dashboard} to={to} onClick={() => closeMenu(to)}><Icon name={icon} size={19} /><span>{label}</span></NavLink>)}{application && <div className="sidebar-note"><Icon name="shield" /><p>Your information.<br /><strong>Your sharing choices.</strong></p></div>}</div></nav>
  </header>;
}
