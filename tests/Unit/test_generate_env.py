import importlib.machinery
import importlib.util
from pathlib import Path
import unittest


ROOT = Path(__file__).parents[2]


def load_generator():
    loader = importlib.machinery.SourceFileLoader('generate_env', str(ROOT / 'docker/bin/generate-env'))
    spec = importlib.util.spec_from_loader('generate_env', loader)
    module = importlib.util.module_from_spec(spec)
    loader.exec_module(module)
    return module


class MergeTest(unittest.TestCase):
    def setUp(self):
        self.generator = load_generator()

    def test_existing_values_are_kept(self):
        merged = self.generator.merge({'SCHEDULER_MEMORY': '8g', 'DB_PASSWORD': 'kept'},
                                      {'SCHEDULER_MEMORY': '2g', 'DB_PASSWORD': 'new'})
        self.assertEqual({'SCHEDULER_MEMORY': '8g', 'DB_PASSWORD': 'kept'}, merged)

    def test_a_retired_stock_default_takes_the_new_default(self):
        self.assertEqual('2g', self.generator.merge({'SCHEDULER_MEMORY': '1g'}, {'SCHEDULER_MEMORY': '2g'})['SCHEDULER_MEMORY'])

    def test_placeholders_never_override(self):
        self.assertEqual('2g', self.generator.merge({'SCHEDULER_MEMORY': ''}, {'SCHEDULER_MEMORY': '2g'})['SCHEDULER_MEMORY'])

    def test_keys_the_template_no_longer_has_are_kept(self):
        self.assertEqual({'CUSTOM': 'x'}, self.generator.merge({'CUSTOM': 'x'}, {}))


if __name__ == '__main__':
    unittest.main()
