import 'core/config/env.dart';
import 'main.dart';

void main() async {
  Env.init(
    baseUrl: 'https://api.example.com/api/v1',
    environmentName: 'production',
  );
  await mainCommon();
}
