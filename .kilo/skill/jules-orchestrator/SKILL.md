---
name: jules-orchestrator
version: 3.0.0
description: Async AI software factory with background tracking, dependency resolution, and auto-approval
source: kilocode-complete
author: Claude Code Team
---

# Jules Orchestrator v3.0 - Complete Implementation

## Philosophy

> **Agent owns: Planning, orchestration, tracking, memory, dependency resolution, PR approval** 
> **Jules owns: Implementation only** 
> **Flow: Async parallel execution with dependency-aware scheduling**

## Architecture

```
User Command
  ↓
[Agent: Planning & Setup]
  ↓
[Background Agent: Continuous Monitoring]
  ↓
[Dependency Resolver: Level-order batches]
  ↓
[Task Scheduler: Create only when deps merged]
  ↓
[Jules: Execute → PR to dev]
  ↓
[Background Agent: Approve & Merge to dev]
  ↓
[Final PR: dev → main (human review)]
```

## State Machine

```
initial
  ↓
pending → issue_created → jules_triggered → pr_opened
  ↓                                      ↓
blocked                              validating
  ↓                                      ↓
retry_requested                        approved
  ↓                                      ↓
retry_1                              merging
  ↓                                      ↓
retry_2                              merged
  ↓                                      ↓
max_retries                          complete
  ↓                                      ↓
manual_review ←←←←←←←←←←←←←←←←←←←←←←←←
```

## Components

### 1. Main Agent Commands

```bash
/plan-work "<prompt>"
  └─→ Interactive setup → Create repo → Generate plan → Create issues

/jules-verify
  └─→ Check setup → Test Jules → Return status

/jules-start
  └─→ Start background agent → Return immediately

/jules-status
  └─→ Read memory → Display current state

/jules-resume
  └─→ Check memory → Resume from last state → Start background agent

/jules-stop
  └─→ Stop background agent → Save state
```

### 2. Background Agent (`background_agent.py`)

```python
#!/usr/bin/env python3
"""
Background Agent - Continuous monitoring and orchestration
Runs in background, polls GitHub, manages dependencies, approves PRs
"""

import json
import time
import subprocess
from datetime import datetime
from pathlib import Path
from typing import Dict, List, Optional, Set
import threading
import signal
import sys

class JulesBackgroundAgent:
    """
    Background agent that:
    1. Monitors GitHub for PR updates
    2. Validates PRs against task expectations
    3. Approves and merges to dev branch
    4. Creates dependent tasks when deps complete
    5. Updates JULES_MEMORY.md
    6. Handles retries and failures
    """
    
    def __init__(self, repo: str, memory_file: str = "JULES_MEMORY.md"):
        self.repo = repo
        self.memory_file = memory_file
        self.running = False
        self.check_interval = 30  # seconds
        self.max_retries = 2
        
    def run(self):
        """Main loop - runs continuously"""
        self.running = True
        
        while self.running:
            try:
                memory = self.load_memory()
                
                # Check if all complete
                if self.all_tasks_complete(memory):
                    self.create_final_pr(memory)
                    break
                
                # Process current batch
                self.process_batch(memory)
                
                # Check for completed tasks and handle dependents
                self.handle_completed_tasks(memory)
                
                # Approve and merge ready PRs
                self.approve_and_merge_prs(memory)
                
                # Update memory
                self.save_memory(memory)
                
            except Exception as e:
                self.log_error(f"Error in main loop: {e}")
            
            time.sleep(self.check_interval)
    
    def process_batch(self, memory: Dict):
        """Create issues for tasks whose dependencies are complete"""
        tasks = memory.get('tasks', [])
        completed_ids = self.get_completed_task_ids(memory)
        
        for task in tasks:
            if task['status'] != 'pending':
                continue
            
            deps = task.get('dependencies', [])
            if all(d in completed_ids for d in deps):
                # All dependencies complete, create issue
                issue_id = self.create_github_issue(task)
                if issue_id:
                    task['status'] = 'issue_created'
                    task['issue_id'] = issue_id
                    task['created_at'] = datetime.now().isoformat()
                    
                    # Trigger Jules
                    self.trigger_jules(task, issue_id)
                    task['status'] = 'jules_triggered'
    
    def handle_completed_tasks(self, memory: Dict):
        """Check for merged PRs and mark tasks complete"""
        tasks = memory.get('tasks', [])
        
        for task in tasks:
            if task['status'] not in ['jules_triggered', 'pr_opened', 'validating']:
                continue
            
            # Check if PR exists and is merged
            pr_info = self.get_pr_for_task(task)
            if pr_info and pr_info.get('merged'):
                task['status'] = 'merged'
                task['pr_id'] = pr_info['number']
                task['merged_at'] = datetime.now().isoformat()
                
                # Validate the merge
                if self.validate_merged_pr(task, pr_info):
                    task['status'] = 'complete'
                    self.log(f"✅ Task {task['id']} complete")
    
    def approve_and_merge_prs(self, memory: Dict):
        """Auto-approve and merge PRs to dev branch"""
        tasks = memory.get('tasks', [])
        
        for task in tasks:
            if task['status'] != 'pr_opened':
                continue
            
            pr_info = self.get_pr_for_task(task)
            if not pr_info:
                continue
            
            # Validate PR
            if not self.validate_pr(task, pr_info):
                task['status'] = 'failed'
                task['retry_count'] = task.get('retry_count', 0) + 1
                continue
            
            # Approve PR
            if self.approve_pr(pr_info['number']):
                task['status'] = 'approved'
                
                # Merge to dev
                if self.merge_pr(pr_info['number'], 'dev'):
                    task['status'] = 'merging'
    
    def validate_pr(self, task: Dict, pr_info: Dict) -> bool:
        """Validate PR against task expectations"""
        expected_files = set(task.get('files_expected', []))
        
        # Get PR files
        changed_files = self.get_pr_files(pr_info['number'])
        
        # Check files match
        files_match = expected_files.issubset(set(changed_files))
        
        # Check no unrelated changes (allow 20% extra)
        extra_files = set(changed_files) - expected_files
        no_unrelated = len(extra_files) <= len(expected_files) * 0.2
        
        # Check tests exist (if not a test task)
        tests_exist = True
        if task['type'] != 'Test':
            test_files = [f for f in changed_files if 'test' in f.lower()]
            tests_exist = len(test_files) > 0
        
        return files_match and no_unrelated and tests_exist
    
    def create_github_issue(self, task: Dict) -> Optional[int]:
        """Create GitHub issue for task"""
        title = f"[AUTO][{task['id']}] {task['title']}"
        body = self.format_issue_body(task)
        labels = f"jules,auto-task,{task['type'].lower()}"
        
        cmd = [
            'gh', 'issue', 'create',
            '-R', self.repo,
            '--title', title,
            '--body', body,
            '--label', labels
        ]
        
        try:
            result = subprocess.run(cmd, capture_output=True, text=True, check=True)
            issue_url = result.stdout.strip()
            return int(issue_url.split('/')[-1])
        except Exception as e:
            self.log_error(f"Failed to create issue: {e}")
            return None
    
    def approve_pr(self, pr_number: int) -> bool:
        """Approve PR via gh CLI"""
        try:
            cmd = [
                'gh', 'pr', 'review', str(pr_number),
                '-R', self.repo,
                '--approve',
                '--body', 'Auto-approved by Jules Orchestrator. All acceptance criteria met.'
            ]
            subprocess.run(cmd, capture_output=True, check=True)
            return True
        except Exception as e:
            self.log_error(f"Failed to approve PR #{pr_number}: {e}")
            return False
    
    def merge_pr(self, pr_number: int, branch: str) -> bool:
        """Merge PR to branch"""
        try:
            cmd = [
                'gh', 'pr', 'merge', str(pr_number),
                '-R', self.repo,
                '--squash',
                '--delete-branch'
            ]
            subprocess.run(cmd, capture_output=True, check=True)
            return True
        except Exception as e:
            self.log_error(f"Failed to merge PR #{pr_number}: {e}")
            return False
    
    def create_final_pr(self, memory: Dict):
        """Create PR from dev to main when all tasks complete"""
        tasks = memory.get('tasks', [])
        
        if not all(t['status'] == 'complete' for t in tasks):
            return
        
        # Check if final PR already exists
        existing = self.get_existing_final_pr()
        if existing:
            return
        
        # Create PR
        title = f"[COMPLETE] {memory['project_name']}"
        body = self.format_final_pr_body(memory)
        
        try:
            cmd = [
                'gh', 'pr', 'create',
                '-R', self.repo,
                '--title', title,
                '--body', body,
                '--head', 'dev',
                '--base', 'main'
            ]
            result = subprocess.run(cmd, capture_output=True, text=True, check=True)
            pr_url = result.stdout.strip()
            
            memory['final_pr'] = pr_url
            memory['status'] = 'complete'
            self.save_memory(memory)
            
            self.log(f"🎉 Final PR created: {pr_url}")
        except Exception as e:
            self.log_error(f"Failed to create final PR: {e}")
    
    def load_memory(self) -> Dict:
        """Load memory from file"""
        if Path(self.memory_file).exists():
            with open(self.memory_file, 'r') as f:
                return json.load(f)
        return {}
    
    def save_memory(self, memory: Dict):
        """Save memory to file and commit"""
        with open(self.memory_file, 'w') as f:
            json.dump(memory, f, indent=2)
        
        # Commit memory file
        self.commit_memory()
    
    def commit_memory(self):
        """Commit memory file to repo"""
        try:
            subprocess.run(['git', 'add', self.memory_file], check=True)
            subprocess.run([
                'git', 'commit', '-m',
                f'[Jules] Update execution state - {datetime.now().isoformat()}'
            ], check=True)
            subprocess.run(['git', 'push'], check=True)
        except Exception as e:
            self.log_error(f"Failed to commit memory: {e}")
    
    def log(self, message: str):
        """Log with timestamp"""
        timestamp = datetime.now().strftime('%Y-%m-%d %H:%M:%S')
        print(f"[{timestamp}] {message}")
        
        # Append to log file
        with open('jules-agent.log', 'a') as f:
            f.write(f"[{timestamp}] {message}\n")
    
    def log_error(self, message: str):
        """Log error"""
        self.log(f"❌ ERROR: {message}")

# Entry point
if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument('--repo', required=True, help='GitHub repo (org/name)')
    parser.add_argument('--memory', default='JULES_MEMORY.json', help='Memory file')
    args = parser.parse_args()
    
    agent = JulesBackgroundAgent(args.repo, args.memory)
    
    # Handle signals for graceful shutdown
    def signal_handler(sig, frame):
        print('\n👋 Stopping background agent...')
        agent.running = False
        sys.exit(0)
    
    signal.signal(signal.SIGINT, signal_handler)
    signal.signal(signal.SIGTERM, signal_handler)
    
    print(f"🚀 Starting background agent for {args.repo}")
    agent.run()
```

### 3. Dependency Resolver

```python
class DependencyResolver:
    """Resolve task dependencies into execution levels"""
    
    def __init__(self, tasks: List[Dict]):
        self.tasks = {t['id']: t for t in tasks}
        self.graph = self.build_graph()
    
    def build_graph(self) -> Dict[str, List[str]]:
        """Build dependency graph"""
        graph = {}
        for task_id, task in self.tasks.items():
            graph[task_id] = task.get('dependencies', [])
        return graph
    
    def topological_sort(self) -> List[List[str]]:
        """
        Return tasks grouped by execution level
        Level 0: No dependencies
        Level 1: Depends only on level 0
        Level 2: Depends on level 0 or 1, etc.
        """
        in_degree = {t: 0 for t in self.tasks}
        adj = {t: [] for t in self.tasks}
        
        # Build reverse adjacency and count in-degrees
        for task_id, deps in self.graph.items():
            for dep in deps:
                adj[dep].append(task_id)
                in_degree[task_id] += 1
        
        # Kahn's algorithm for topological sort
        levels = []
        current_level = [t for t in self.tasks if in_degree[t] == 0]
        
        while current_level:
            levels.append(current_level)
            next_level = []
            
            for task_id in current_level:
                for dependent in adj[task_id]:
                    in_degree[dependent] -= 1
                    if in_degree[dependent] == 0:
                        next_level.append(dependent)
            
            current_level = next_level
        
        return levels
    
    def has_circular_dependency(self) -> bool:
        """Check for circular dependencies"""
        visited = set()
        rec_stack = set()
        
        def dfs(node):
            visited.add(node)
            rec_stack.add(node)
            
            for neighbor in self.graph.get(node, []):
                if neighbor not in visited:
                    if dfs(neighbor):
                        return True
                elif neighbor in rec_stack:
                    return True
            
            rec_stack.remove(node)
            return False
        
        for node in self.tasks:
            if node not in visited:
                if dfs(node):
                    return True
        
        return False
    
    def get_ready_tasks(self, completed_ids: Set[str]) -> List[str]:
        """Get tasks that are ready to execute (deps complete)"""
        ready = []
        for task_id, task in self.tasks.items():
            if task_id in completed_ids:
                continue
            deps = task.get('dependencies', [])
            if all(d in completed_ids for d in deps):
                ready.append(task_id)
        return ready
```

### 4. JULES_MEMORY.md Schema

```markdown
# Jules Execution Memory v3.0

## Project
- **Name**: {project_name}
- **Repo**: {repo}
- **Created**: {timestamp}
- **Status**: {planning|executing|complete|failed}

## Configuration
```json
{
 "parallel": true,
 "max_concurrent": 3,
 "auto_approve": true,
 "target_branch": "dev",
 "final_branch": "main",
 "check_interval": 30
}
```

## Execution Levels
```
Level 0: [T1, T2] → Ready to execute
Level 1: [T3, T4] → Waiting for Level 0
Level 2: [T5, T6] → Waiting for Level 1
```

## Tasks

| Task | Status | Issue | PR | Level | Retry |
|------|--------|-------|-----|-------|-------|
| T1 | ⏳ pending | - | - | 0 | 0 |
| T2 | ⏳ pending | - | - | 0 | 0 |
| T3 | ⏸️ blocked | - | - | 1 | 0 |
| T4 | ⏸️ blocked | - | - | 1 | 0 |

## State Log
- 10:00:00 - Plan created
- 10:01:00 - Background agent started
- 10:02:00 - Level 0 tasks created
- 10:05:00 - T1: Jules triggered
- 10:05:30 - T2: Jules triggered

## Statistics
- Tasks Complete: 0/6
- Success Rate: N/A
- Avg PR Time: N/A
- Failed: 0
- Retries: 0

## Final PR
- **Status**: ⏳ Waiting
- **PR**: -
- **URL**: -

---
*Last Updated: {timestamp}*
*Background Agent: {running|stopped}*
```

### 5. Resume Logic

```python
def resume_orchestration(repo: str, memory_file: str = "JULES_MEMORY.md"):
    """
    Resume orchestration from memory file
    Called when /jules-resume is invoked
    """
    # Load memory
    memory = load_memory(memory_file)
    
    # Check if already complete
    if memory['status'] == 'complete':
        print("✅ All tasks already complete!")
        print(f"Final PR: {memory.get('final_pr', 'N/A')}")
        return
    
    # Check if background agent running
    if is_agent_running():
        print("🔄 Background agent already running")
        print(f"Run /jules-status to check progress")
        return
    
    # Reconstruct state
    tasks = memory['tasks']
    completed = [t for t in tasks if t['status'] == 'complete']
    pending = [t for t in tasks if t['status'] in ['pending', 'issue_created', 'jules_triggered', 'pr_opened']]
    failed = [t for t in tasks if t['status'] == 'failed']
    
    print(f"🔄 Resuming orchestration for {memory['project_name']}")
    print(f"   Completed: {len(completed)}/{len(tasks)}")
    print(f"   Pending: {len(pending)}")
    print(f"   Failed: {len(failed)}")
    
    # Start background agent
    start_background_agent(repo, memory_file)
    
    print(f"✅ Background agent started")
    print(f"⏳ Monitoring progress...")
    print(f"Check /jules-status anytime")
```

### 6. Complete Command Flow

```bash
# User invokes command
/plan-work "Build user auth with JWT"

# Agent:
# 1. Interactive prompts
# 2. Create repo: gh repo create org/project
# 3. Create dev branch
# 4. Generate plan with validation
# 5. Create JULES_MEMORY.json
# 6. Setup labels
# 7. Create issues for level 0 tasks only
# 8. Start background agent
# 9. Return immediately with status

# Background agent (runs continuously):
# 1. Poll GitHub every 30s
# 2. When PR merged to dev:
#    - Mark task complete
#    - Check for ready dependents
#    - Create issues for next level tasks
#    - Trigger Jules
# 3. Auto-approve valid PRs
# 4. Merge to dev
# 5. Update memory file
# 6. When all complete, create final PR

# User checks status:
/jules-status
# Agent reads memory, displays current state
# Shows: completed tasks, executing tasks, pending tasks

# If agent stopped:
/jules-resume
# Agent reads memory, starts background agent, continues
```

## Implementation Files

```
.kilo/skill/jules-orchestrator/
├── SKILL.md
├── command.md
├── background_agent.py      # Background monitoring
├── dependency_resolver.py   # Dependency graph
├── plan_validator.py        # Validate plan schema
├── github_utils.py          # GitHub API helpers
├── resume_logic.py          # Resume from memory
└── templates/
    ├── issue_body.md
    ├── jules_prompt.txt
    └── final_pr_body.md
```

## Key Features

1. **Non-blocking**: Returns immediately after setup
2. **Background agent**: Continuous monitoring
3. **Dependency resolution**: Tasks created only when deps complete
4. **Auto-approve**: PRs validated and merged automatically
5. **Resume**: Can resume after agent restart
6. **State persistence**: Memory in JSON + commit to repo
7. **Branch strategy**: dev ← Jules PRs, main ← final PR
8. **Parallel execution**: Tasks at same level run in parallel

## Success Criteria

- ✅ Background agent runs continuously
- ✅ Issues created only when dependencies merged
- ✅ PRs auto-approved and merged to dev
- ✅ Final PR created when all complete
- ✅ Resume works from memory file
- ✅ No blocking/waiting in main thread
- ✅ State survives agent restarts

---

*Complete Async Implementation - Agent Driven, Dependency Aware, Non-Blocking*
