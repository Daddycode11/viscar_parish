"""Backward-compatible entry point for the complete revision regression suite."""
from pathlib import Path
import runpy
runpy.run_path(str(Path(__file__).with_name('test_separation.py')), run_name='__main__')
