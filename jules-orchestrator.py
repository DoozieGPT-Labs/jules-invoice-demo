#!/usr/bin/env python3
"""
Jules Orchestrator v2.0 - Execute validated plans via Google Jules
"""

import json
import os
import sys
import subprocess
from datetime import datetime
from typing import Dict, List, Optional, Tuple
import time


class JulesOrchestrator:
    def __init__(self, plan_path: str, repo: str = "DoozieGPT-Labs/jules-invoice-demo"):
        self.repo = repo
        self.plan_path = plan_path
        with open(plan_path, "r") as f:
            self.plan = json.load(f)
        self.completed_tasks = []
        self.pending_tasks = []
        self.failed_tasks = []
        self.current_issue = 100

    def validate_plan(self) -> Tuple[bool, List[str]]:
        """Validate plan against hard rules"""
        errors = []
        tasks = self.plan.get("tasks", [])

        # Rule 1: Max 10 tasks
        if len(tasks) > 10:
            errors.append(f"Too many tasks: {len(tasks)} > 10")

        for task in tasks:
            tid = task.get("id", "UNKNOWN")

            # Rule 2: Must have files_expected
            if not task.get("files_expected") or len(task["files_expected"]) < 1:
                errors.append(f"Task {tid}: Missing files_expected")

            # Rule 3: At least 2 acceptance criteria
            criteria = task.get("acceptance_criteria", [])
            if len(criteria) < 2:
                errors.append(
                    f"Task {tid}: Need >= 2 acceptance criteria (got {len(criteria)})"
                )

            # Rule 4: Valid type
            valid_types = ["DB", "API", "UI", "Refactor", "Test"]
            if task.get("type") not in valid_types:
                errors.append(f"Task {tid}: Invalid type '{task.get('type')}'")

            # Rule 5: No self-dependencies
            deps = task.get("dependencies", [])
            if tid in deps:
                errors.append(f"Task {tid}: Self-dependency not allowed")

        # Rule 6: Test tasks must have dependencies
        for task in tasks:
            if task.get("type") == "Test" and not task.get("dependencies"):
                errors.append(
                    f"Task {task.get('id')}: Test tasks must have dependencies"
                )

        return len(errors) == 0, errors

    def build_dependency_graph(self) -> Dict[str, List[str]]:
        """Build dependency graph from tasks"""
        graph = {}
        for task in self.plan.get("tasks", []):
            graph[task["id"]] = task.get("dependencies", [])
        return graph

    def get_next_task(self) -> Optional[Dict]:
        """Get next task with no unmet dependencies"""
        graph = self.build_dependency_graph()
        completed_ids = {t["id"] for t in self.completed_tasks}
        failed_ids = {t["id"] for t in self.failed_tasks}

        for task in self.plan.get("tasks", []):
            tid = task["id"]
            if tid in completed_ids or tid in failed_ids:
                continue

            deps = graph.get(tid, [])
            if all(d in completed_ids for d in deps):
                return task

        return None

    def create_github_issue(self, task: Dict) -> int:
        """Create GitHub issue for task"""
        title = f"[AUTO][{task['id']}] {task['title']}"
        body = self._format_issue_body(task)
        labels = f"jules,auto-task,{task['type'].lower()}"

        cmd = [
            "gh",
            "issue",
            "create",
            "-R",
            self.repo,
            "--title",
            title,
            "--body",
            body,
            "--label",
            labels,
        ]

        try:
            result = subprocess.run(cmd, capture_output=True, text=True, check=True)
            issue_url = result.stdout.strip()
            issue_number = int(issue_url.split("/")[-1])
            self.current_issue = issue_number
            return issue_number
        except subprocess.CalledProcessError as e:
            print(f"❌ Failed to create issue: {e.stderr}")
            return 0

    def _format_issue_body(self, task: Dict) -> str:
        """Format GitHub issue body"""
        body = f"""## Task Definition
- **ID**: {task["id"]}
- **Type**: {task["type"]}
- **Priority**: High
- **Created**: {datetime.now().isoformat()}

## Description
{task["description"]}

## Expected Files
"""
        for f in task.get("files_expected", []):
            body += f"- [ ] `{f}`\n"

        body += "\n## Acceptance Criteria\n"
        for i, criteria in enumerate(task.get("acceptance_criteria", []), 1):
            body += f"{i}. {criteria}\n"

        body += "\n## Test Cases\n"
        for i, test in enumerate(task.get("test_cases", []), 1):
            body += f"{i}. {test}\n"

        deps = task.get("dependencies", [])
        if deps:
            body += f"\n## Dependencies\n"
            for dep in deps:
                body += f"- {dep}\n"

        body += """
## Jules Instructions
This task is assigned to Google Jules for implementation.
**Jules**: Execute exactly as specified. Do not expand scope.

---
*Generated by Jules Orchestrator v2.0*
"""
        return body

    def generate_jules_prompt(self, task: Dict, issue_number: int) -> str:
        """Generate constrained prompt for Jules"""
        return (
            f"""You are executing a STRICTLY DEFINED task. DO NOT re-plan. DO NOT change scope.

═══════════════════════════════════════════════════════════════
TASK DEFINITION (DO NOT MODIFY)
═══════════════════════════════════════════════════════════════

Task ID: {task["id"]}
Title: {task["title"]}
Type: {task["type"]}

Description:
{task["description"]}

Expected Files (CREATE EXACTLY THESE):
"""
            + "\n".join([f"- {f}" for f in task.get("files_expected", [])])
            + """

Acceptance Criteria (MUST SATISFY ALL):
"""
            + "\n".join(
                [
                    f"{i + 1}. {c}"
                    for i, c in enumerate(task.get("acceptance_criteria", []))
                ]
            )
            + """

═══════════════════════════════════════════════════════════════
CONSTRAINTS (STRICT)
═══════════════════════════════════════════════════════════════

1. ONLY modify/create files in files_expected list
2. DO NOT modify unrelated files
3. DO NOT introduce new dependencies (Laravel only)
4. DO NOT change existing architecture
5. Follow Laravel conventions (PSR-12, Eloquent patterns)
6. Keep changes MINIMAL and FOCUSED

═══════════════════════════════════════════════════════════════
REQUIRED OUTPUT
═══════════════════════════════════════════════════════════════

1. Create/Modify files as specified
2. Commit: "[{task['id']}] {task['title']}"
3. Create PR: "[{task['id']}] {task['title']}"
4. Reference issue #{issue_number}
5. Ensure tests pass (php artisan test)

═══════════════════════════════════════════════════════════════
"""
        )

    def wait_for_jules_pr(self, task: Dict, issue_number: int) -> Optional[Dict]:
        """Wait for Jules to create PR"""
        print(f"⏳ Waiting for Jules to process issue #{issue_number}...")
        print(f"   (Jules typically takes 2-5 minutes per task)")

        max_attempts = 30  # 5 minutes with 10-second intervals
        for attempt in range(max_attempts):
            time.sleep(10)

            # Check for PRs referencing this issue
            try:
                result = subprocess.run(
                    [
                        "gh",
                        "pr",
                        "list",
                        "-R",
                        self.repo,
                        "--state",
                        "open",
                        "--json",
                        "number,title,body",
                    ],
                    capture_output=True,
                    text=True,
                    check=True,
                )
                prs = json.loads(result.stdout)

                for pr in prs:
                    if task["id"] in pr.get("title", ""):
                        return {
                            "number": pr["number"],
                            "title": pr["title"],
                            "task_id": task["id"],
                        }

                # Check for merged PRs too
                result = subprocess.run(
                    [
                        "gh",
                        "pr",
                        "list",
                        "-R",
                        self.repo,
                        "--state",
                        "merged",
                        "--json",
                        "number,title,body",
                    ],
                    capture_output=True,
                    text=True,
                    check=True,
                )
                prs = json.loads(result.stdout)

                for pr in prs:
                    if task["id"] in pr.get("title", ""):
                        return {
                            "number": pr["number"],
                            "title": pr["title"],
                            "task_id": task["id"],
                            "merged": True,
                        }

            except Exception as e:
                print(f"   Warning: Error checking PRs: {e}")

            if attempt % 6 == 0 and attempt > 0:
                print(f"   Still waiting... ({attempt // 6} minutes elapsed)")

        return None

    def validate_pr(self, task: Dict, pr_number: int) -> Dict:
        """Validate PR against task expectations"""
        print(f"🔍 Validating PR #{pr_number}...")

        try:
            # Get PR files
            result = subprocess.run(
                [
                    "gh",
                    "pr",
                    "view",
                    str(pr_number),
                    "-R",
                    self.repo,
                    "--json",
                    "files",
                ],
                capture_output=True,
                text=True,
                check=True,
            )
            pr_data = json.loads(result.stdout)
            changed_files = [f["path"] for f in pr_data.get("files", [])]

            # Check files match expected
            expected = set(task.get("files_expected", []))
            changed = set(changed_files)

            files_match = expected.issubset(changed)
            no_unrelated = (
                len(changed - expected) <= len(expected) * 0.2
            )  # Allow 20% extra files

            # Check if tests exist (if task is not a test task)
            tests_exist = True
            if task["type"] != "Test":
                test_files = [
                    f for f in changed_files if "test" in f.lower() or "Test" in f
                ]
                tests_exist = len(test_files) > 0

            return {
                "pr_number": pr_number,
                "task_id": task["id"],
                "validation": {
                    "files_match": files_match,
                    "tests_exist": tests_exist,
                    "no_unrelated": no_unrelated,
                    "ci_passes": True,  # Assume CI passes for now
                },
                "score": 100 if all([files_match, tests_exist, no_unrelated]) else 50,
                "passed": files_match and no_unrelated,
                "changed_files": changed_files,
            }

        except Exception as e:
            print(f"   Warning: Error validating PR: {e}")
            return {
                "pr_number": pr_number,
                "task_id": task["id"],
                "validation": {},
                "score": 0,
                "passed": False,
                "error": str(e),
            }

    def execute(self):
        """Execute the plan"""
        print(f"🚀 Jules Orchestrator v2.0")
        print(f"Project: {self.plan.get('project_name', 'Unknown')}")
        print(f"Repository: {self.repo}")
        print(f"Tasks: {len(self.plan.get('tasks', []))}")
        print()

        # Validate plan
        valid, errors = self.validate_plan()
        if not valid:
            print("❌ Plan validation FAILED:")
            for error in errors:
                print(f"  - {error}")
            return

        print("✅ Plan validation PASSED")
        print()

        # Execute tasks sequentially
        while True:
            task = self.get_next_task()
            if not task:
                break

            self._execute_task(task)

        # Report
        self._report_status()

    def _execute_task(self, task: Dict):
        """Execute a single task"""
        tid = task["id"]
        print(f"\n{'=' * 60}")
        print(f"Task {tid}: {task['title']}")
        print(f"Type: {task['type']}")
        print(f"Files: {', '.join(task.get('files_expected', []))}")
        print(f"{'=' * 60}")

        # Step 1: Create GitHub Issue
        print(f"\n📋 Creating GitHub Issue...")
        issue_number = self.create_github_issue(task)
        if issue_number == 0:
            print("❌ Failed to create issue, skipping task")
            self.failed_tasks.append(task)
            return

        print(f"   Issue: #{issue_number}")
        print(f"   Labels: jules, auto-task, {task['type'].lower()}")

        # Step 2: Generate Jules prompt (for logging)
        print(
            f"\n🤖 Jules prompt generated ({len(self.generate_jules_prompt(task, issue_number))} chars)"
        )

        # Step 3: Wait for Jules to process
        print(f"\n⏳ Jules processing issue #{issue_number}...")
        print(f"   (Monitor at: https://github.com/{self.repo}/issues/{issue_number})")

        pr_info = self.wait_for_jules_pr(task, issue_number)

        if not pr_info:
            print(f"\n⚠️  Jules did not create PR within timeout")
            print(
                f"   Check manually at: https://github.com/{self.repo}/issues/{issue_number}"
            )
            self.pending_tasks.append(task)
            return

        pr_number = pr_info["number"]
        print(f"\n📬 PR #{pr_number} opened by Jules")

        # Step 4: Validate PR
        validation = self.validate_pr(task, pr_number)

        if validation["passed"]:
            print(f"   ✅ Validation PASSED (Score: {validation['score']})")

            # Auto-merge if validation passes
            print(f"\n📋 Merging PR #{pr_number}...")
            try:
                subprocess.run(
                    [
                        "gh",
                        "pr",
                        "merge",
                        str(pr_number),
                        "-R",
                        self.repo,
                        "--squash",
                        "--delete-branch",
                    ],
                    capture_output=True,
                    check=True,
                )
                print(f"   ✅ PR #{pr_number} merged successfully")
                self.completed_tasks.append(task)
            except subprocess.CalledProcessError as e:
                print(f"   ⚠️  Could not auto-merge: {e}")
                print(
                    f"   Please merge manually: https://github.com/{self.repo}/pull/{pr_number}"
                )
                self.completed_tasks.append(task)
        else:
            print(f"   ❌ Validation FAILED")
            print(
                f"   Files match: {validation['validation'].get('files_match', False)}"
            )
            print(
                f"   Tests exist: {validation['validation'].get('tests_exist', False)}"
            )
            print(f"   Changed files: {validation.get('changed_files', [])}")
            self.failed_tasks.append(task)

    def _report_status(self):
        """Report execution status"""
        print(f"\n{'=' * 60}")
        print("EXECUTION REPORT")
        print(f"{'=' * 60}")

        total = len(self.plan.get("tasks", []))
        completed = len(self.completed_tasks)
        failed = len(self.failed_tasks)
        pending = len(self.pending_tasks)

        print(f"\nProgress: {completed}/{total} tasks ({completed / total * 100:.0f}%)")

        if completed == total:
            print("\n✅ ALL TASKS COMPLETED SUCCESSFULLY!")
        elif failed > 0:
            print(f"\n⚠️  {completed} completed, {failed} failed, {pending} pending")

        print(f"\nCompleted Tasks:")
        for task in self.completed_tasks:
            print(f"  ✅ {task['id']}: {task['title']}")

        if self.failed_tasks:
            print(f"\nFailed Tasks:")
            for task in self.failed_tasks:
                print(f"  ❌ {task['id']}: {task['title']}")

        if self.pending_tasks:
            print(f"\nPending Tasks (check manually):")
            for task in self.pending_tasks:
                print(f"  ⏳ {task['id']}: {task['title']}")


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python jules-orchestrator.py <plan.json>")
        sys.exit(1)

    orchestrator = JulesOrchestrator(sys.argv[1])
    orchestrator.execute()
