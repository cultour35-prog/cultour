import 'package:flutter_test/flutter_test.dart';

import 'package:cultour_mobile/main.dart';

void main() {
  testWidgets('CulTour app boots to a gate', (tester) async {
    await tester.pumpWidget(const CulTourApp());
    expect(find.byType(CulTourApp), findsOneWidget);
  });
}
