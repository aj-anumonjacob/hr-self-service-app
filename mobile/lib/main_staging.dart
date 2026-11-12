import 'core/config/env.dart';
import 'main.dart';

void main() async {
  Env.init(
    baseUrl: 'https://staging-api.example.com/v1',
    environmentName: 'staging',
  );
  await mainCommon();
}
