// Compatibility wrapper: failures are surfaced by the shared resource state.
import {useEffect} from 'react';
import {useResource} from './useResource.js';
export function useAutoRefresh(fetcher,onData){const resource=useResource(fetcher);useEffect(()=>{if(resource.data!==null)onData(resource.data);},[resource.data,onData]);return resource;}
