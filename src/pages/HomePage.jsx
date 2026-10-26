import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext.jsx';
import { useResource } from '../hooks/useResource.js';
import { fetchJobs } from '../services/jobs.js';
import JobGrid from '../components/JobGrid.jsx';
import RequestState from '../components/RequestState.jsx';
import RecommendationsPage from './RecommendationsPage.jsx';
import Icon from '../components/Icon.jsx';

export default function HomePage() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [query, setQuery] = useState('');
  const [location, setLocation] = useState('');
  const resource = useResource(() => fetchJobs({ per_page: 3 }), 'featured');
  function search(event) {
    event.preventDefault();
    const params = new URLSearchParams();
    if (query.trim()) params.set('q', query.trim());
    if (location.trim()) params.set('location', location.trim());
    navigate(`/jobs?${params}`);
  }
  return <main className="home-page">
    <section className="home-hero"><div className="hero-grid">
      <div className="hero-copy"><p className="eyebrow">A world of work. Open to you.</p><h1>Find opportunities.<br /><span>Build your future.</span></h1><p>Discover inclusive employment opportunities, connect with employers, and take the next step in your career.</p><div className="action-row"><Link className="primary-button" to="/jobs">Explore opportunities <Icon name="arrow" /></Link><Link className="banner-link" to="/employers">Hiring? Start here</Link></div><p className="hero-note"><Icon name="shield" size={18} /> Your strengths come first. Sharing stays your choice.</p></div>
      <div className="hero-path"><div className="path-heading"><span className="metric-icon"><Icon name="briefcase" /></span><div><p className="eyebrow">Your career, your way</p><h2>Make room for possibility.</h2></div></div>{[['search','Discover your next role','Explore jobs by skills, location and work arrangement.'],['file','Bring your strengths forward','Build a resume that tells your professional story.'],['message','Start a useful conversation','Discuss practical recruitment arrangements privately.']].map(([icon,title,description],i) => <div className="path-step" key={title}><span className="path-number">0{i + 1}</span><Icon name={icon} /><div><h3>{title}</h3><p>{description}</p></div></div>)}<p className="path-caption">A clearer path from possibility to opportunity.</p></div>
      <form className="hero-search" onSubmit={search}><label htmlFor="heroSearchInput"><span>What do you want to do?</span><div><Icon name="search" /><input id="heroSearchInput" value={query} onChange={e => setQuery(e.target.value)} placeholder="Job title, keyword or skill" /></div></label><label htmlFor="heroLocationInput"><span>Where would you like to work?</span><div><Icon name="briefcase" /><input id="heroLocationInput" value={location} onChange={e => setLocation(e.target.value)} placeholder="City or location" /></div></label><button className="primary-button" type="submit">Search jobs <Icon name="arrow" /></button></form>
    </div></section>
    <section className="section-container"><div className="section-heading"><div><p className="eyebrow">Take your next step</p><h2>Opportunities worth exploring</h2><p>Explore current listings and employer-reported workplace support.</p></div><Link to="/jobs">View all jobs <Icon name="arrow" size={18} /></Link></div><RequestState resource={resource} />{resource.data && <JobGrid jobs={resource.data.jobs} totalCount={resource.data.meta.total} />}</section>
    {user?.role === 'applicant' && !user.email_verification_required && <div className="home-recommendations"><RecommendationsPage preview /></div>}
    <section className="how-section"><div className="section-container"><p className="eyebrow">Simple steps. Meaningful progress.</p><h2>A platform built around your next move</h2><div className="benefit-grid">{[['01','Start with your strengths','Create a profile that highlights your skills, experience and preferred work arrangements.'],['02','Find the right possibilities','Search current opportunities and review why a recommended role might be relevant.'],['03','Move forward with clarity','Track your applications, prepare your resume and discuss recruitment arrangements.']].map(([number,title,body]) => <div key={number}><span className="step-number">{number}</span><h3>{title}</h3><p>{body}</p></div>)}</div></div></section>
    <section className="section-container"><div className="home-access"><span className="access-emblem"><Icon name="user" size={66} /></span><div><p className="eyebrow">Designed for different ways of working</p><h2>More control. Fewer barriers.</h2><p>Adjust text size, contrast, reading fonts and motion to suit you. Explore employer-reported support and choose what to share, when to share it.</p><Link to="/about">Learn how PWDWork works <Icon name="arrow" size={18} /></Link></div></div><div className="employer-banner"><div><p className="eyebrow">For thoughtful employers</p><h2>Great teams start with open possibilities.</h2><p>Describe your opportunities clearly and connect with people ready to contribute.</p></div><Link className="primary-button" to="/register?role=employer">Create an employer account <Icon name="arrow" /></Link></div></section>
  </main>;
}
